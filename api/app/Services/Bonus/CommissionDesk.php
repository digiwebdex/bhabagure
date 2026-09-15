<?php

namespace App\Services\Bonus;

use App\Enums\BookingStatus;
use App\Enums\StaffStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingOwnerChanged;
use App\Models\BonusAccount;
use App\Models\BonusTransaction;
use App\Models\Booking;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Notifications\AdminAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Automatic commission (docs/phase-7-hr-attendance-bonus-wallet.md §12 step 4; rates in config bhabaghure.commission).
 *
 *  - A booking earns its owner a commission while it is confirmed or completed: the tour rate of the sale before VAT,
 *    rounded to the taka, credited to the owner's bonus ledger with the rule it was made with.
 *  - Only owners whose role grants `commission.view_own` earn (sales agents and tour operators); an admin or the super
 *    admin owning a booking doesn't.
 *  - Cancelled: the commission is reversed. Reassigned: reversed for the old owner and credited to the new one. Confirmed
 *    by an online payment while nobody owned it (the pool holds inquiries only): credited to whoever an admin assigns it to.
 *  - These system entries have no author. A system reversal goes through even when the money was already withdrawn: the
 *    balance goes below zero and later credits make it up, and no withdrawal can be asked for meanwhile.
 *  - A commission reversed by hand (bonus.manage, with a reason) is withheld: it isn't credited to that person again for
 *    that booking.
 *  - At a Dhaka month's end, anyone with at least the threshold of bookings confirmed that month, still earning when the
 *    bonus is posted, gets the volume rate of all those bookings' sale as one entry. A later cancellation reverses that
 *    booking's commission, not the posted bonus.
 *
 * sync() works out what a booking's commission should be from its state and puts the ledger right, so running it twice,
 * late, or after events arrive out of order changes nothing.
 */
final class CommissionDesk
{
    public const PERMISSION = 'commission.view_own';

    public function __construct(
        private readonly BonusDesk $desk,
        private readonly AuditLogger $audit,
    ) {}

    /** Who earns commission on the bookings they own. Checked on the role, so the super admin's all-access doesn't count. */
    public static function earns(?Staff $staff): bool
    {
        return $staff !== null && $staff->status === StaffStatus::Active && $staff->checkPermissionTo(self::PERMISSION);
    }

    /** The sale commission is figured on: the booking total less its VAT (after any discount, add-ons included). */
    public static function base(Booking $booking): float
    {
        return round(max(0, (float) $booking->total_amount - (float) $booking->vat_amount), 2);
    }

    /** @return array{type: string, rate: float} */
    public static function rule(string $type): array
    {
        return ['type' => $type, 'rate' => (float) config("bhabaghure.commission.rates.{$type}")];
    }

    /** Listener for BookingConfirmed, BookingCancelled and BookingOwnerChanged. A failure never undoes the booking change. */
    public function onBookingChanged(BookingConfirmed|BookingCancelled|BookingOwnerChanged $event): void
    {
        try {
            $this->sync($event->booking);
        } catch (Throwable $e) {
            report($e);
            AdminAlerts::once("commission-sync:{$event->booking->id}",
                "Commission for booking {$event->booking->reference} couldn't be updated: {$e->getMessage()}. It is retried when the month's volume bonus is posted, or when the booking changes again.",
                'bonus.manage');
        }
    }

    /** Puts one booking's commission right for its status and owner. */
    public function sync(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $owner = $locked->assigned_staff_id !== null ? Staff::query()->find($locked->assigned_staff_id) : null;
            $earning = in_array($locked->status, [BookingStatus::Confirmed, BookingStatus::Completed], true) && self::earns($owner);
            $ownerAccount = $earning ? $this->desk->account($owner) : null;

            $active = BonusTransaction::query()->where('booking_id', $locked->id)->where('kind', BonusTransaction::COMMISSION)
                ->whereDoesntHave('reversedBy')->get();
            $accountIds = $active->pluck('bonus_account_id')->push($ownerAccount?->id)->filter()->unique()->sort()->values();
            // In id order, as every other writer to these accounts takes them one at a time.
            BonusAccount::query()->whereKey($accountIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($active as $entry) {
                if ($entry->bonus_account_id !== $ownerAccount?->id) {
                    $this->reverse($entry, $locked, $owner);
                }
            }

            if ($ownerAccount === null || $active->contains('bonus_account_id', $ownerAccount->id)) {
                return;
            }
            $withheld = BonusTransaction::query()->where('booking_id', $locked->id)->where('kind', BonusTransaction::COMMISSION)
                ->where('bonus_account_id', $ownerAccount->id)
                ->whereHas('reversedBy', fn ($reversal) => $reversal->whereNotNull('created_by_staff_id'))->exists();
            if (! $withheld) {
                $this->credit($locked, $owner, $ownerAccount);
            }
        });
    }

    /**
     * Posts the volume bonus for a Dhaka month that is over ('YYYY-MM'). Safe to run again: a person's bonus for a month is
     * posted once. Returns how many were posted.
     */
    public function postVolumeBonuses(string $month): int
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("'{$month}' is not a month (YYYY-MM).");
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Dhaka');
        $end = $start->addMonth();
        if ($end->isFuture()) {
            throw new InvalidArgumentException("{$month} isn't over yet.");
        }

        // Catch up first on anything a failed listener left behind, so the count is right.
        $bookingIds = Booking::query()->where('confirmed_at', '>=', $start->utc())->where('confirmed_at', '<', $end->utc())->orderBy('id')->pluck('id');
        foreach ($bookingIds as $id) {
            $this->sync(Booking::query()->findOrFail($id));
        }

        $earning = Booking::query()->whereKey($bookingIds)->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])->pluck('id');
        $threshold = (int) config('bhabaghure.commission.volume.threshold');
        $rate = (float) config('bhabaghure.commission.volume.rate');
        $posted = 0;

        BonusTransaction::query()->whereIn('booking_id', $earning)->where('kind', BonusTransaction::COMMISSION)->whereDoesntHave('reversedBy')
            ->get()->groupBy('bonus_account_id')
            ->filter(fn ($entries) => $entries->count() >= $threshold)
            ->each(function ($entries, $accountId) use ($month, $threshold, $rate, &$posted) {
                $base = round($entries->sum(fn (BonusTransaction $entry) => (float) ($entry->rule['base'] ?? 0)), 2);
                $amount = round($base * $rate / 100);
                if ($amount <= 0) {
                    return;
                }

                try {
                    DB::transaction(function () use ($accountId, $month, $threshold, $rate, $entries, $base, $amount, &$posted) {
                        $account = BonusAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
                        if (BonusTransaction::query()->where('bonus_account_id', $account->id)->where('kind', BonusTransaction::VOLUME)->where('period', $month)->exists()) {
                            return;
                        }
                        $entry = BonusTransaction::query()->create([
                            'bonus_account_id' => $account->id, 'direction' => BonusTransaction::CREDIT, 'amount' => $amount, 'kind' => BonusTransaction::VOLUME,
                            'period' => $month,
                            'rule' => ['type' => 'volume', 'month' => $month, 'rate' => $rate, 'threshold' => $threshold, 'bookings' => $entries->count(), 'base' => $base],
                        ]);
                        $this->audit->record('bonus.volume_bonus_credited', null, $account->staff()->first(), [
                            'entry' => $entry->id, 'month' => $month, 'amount' => $amount, 'bookings' => $entries->count(), 'base' => $base, 'rate' => $rate,
                        ]);
                        $posted++;
                    });
                } catch (UniqueConstraintViolationException) {
                    // Another run posted it first.
                }
            });

        return $posted;
    }

    private function credit(Booking $booking, Staff $owner, BonusAccount $account): void
    {
        $rule = self::rule('tour') + ['base' => self::base($booking), 'booking' => $booking->reference];
        $amount = round($rule['base'] * $rule['rate'] / 100);
        if ($amount <= 0) {
            return;
        }

        $entry = BonusTransaction::query()->create([
            'bonus_account_id' => $account->id, 'direction' => BonusTransaction::CREDIT, 'amount' => $amount,
            'kind' => BonusTransaction::COMMISSION, 'booking_id' => $booking->id, 'rule' => $rule,
        ]);
        $this->audit->record('bonus.commission_credited', null, $owner, [
            'entry' => $entry->id, 'booking' => $booking->reference, 'amount' => $amount, 'base' => $rule['base'], 'rate' => $rule['rate'],
        ]);
    }

    private function reverse(BonusTransaction $entry, Booking $booking, ?Staff $owner): void
    {
        $cause = match (true) {
            $booking->status === BookingStatus::Cancelled => 'booking_cancelled',
            $owner === null => 'returned_to_pool',
            default => 'reassigned',
        };
        $reversal = BonusTransaction::query()->create([
            'bonus_account_id' => $entry->bonus_account_id, 'direction' => BonusTransaction::DEBIT, 'amount' => $entry->amount,
            'kind' => BonusTransaction::REVERSAL, 'booking_id' => $booking->id, 'reverses_id' => $entry->id,
            'rule' => array_filter(['cause' => $cause, 'booking' => $booking->reference, 'to' => $cause === 'reassigned' ? $owner?->name : null]),
        ]);
        $this->audit->record('bonus.commission_reversed', null, $entry->account()->first()?->staff()->first(), [
            'entry' => $entry->id, 'reversal' => $reversal->id, 'booking' => $booking->reference, 'amount' => $entry->amount, 'cause' => $cause,
        ]);
    }
}
