<?php

namespace App\Services\Bonus;

use App\Enums\TransactionDirection;
use App\Events\CashEntryReversed;
use App\Models\BonusAccount;
use App\Models\BonusTransaction;
use App\Models\BonusWithdrawal;
use App\Models\BonusWithdrawalEvent;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bonus accounts (docs/phase-7-hr-attendance-bonus-wallet.md §7).
 *
 *  - balance   = credits − debits in the account's append-only ledger;
 *  - available = balance − open (pending or approved) withdrawals, so money asked for isn't asked for twice.
 *
 * bonus.manage credits by hand and reverses entries, and decides withdrawals: approve or reject, then mark paid, which
 * debits the account and records a company cash-out under Staff bonuses. Reversing that cash-out in the cash book puts
 * the money back (a reversal entry) and the withdrawal back to approved. Nobody credits, reverses or decides their own,
 * except the super admin. Every withdrawal step is an append-only event, and every decision is in the audit log.
 */
final class BonusDesk
{
    /** The smallest withdrawal a staff member can ask for, in taka. */
    public const MIN_WITHDRAWAL = 500;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerService $ledger,
    ) {}

    /** The person's account, opened the first time it's needed (and with the staff record). */
    public function account(Staff $person): BonusAccount
    {
        return BonusAccount::query()->createOrFirst(['staff_id' => $person->id]);
    }

    public static function balance(int $accountId): float
    {
        return round((float) BonusTransaction::query()->where('bonus_account_id', $accountId)
            ->selectRaw("coalesce(sum(case when direction = 'credit' then amount else -amount end), 0) as balance")->value('balance'), 2);
    }

    public static function held(int $accountId, ?int $exceptWithdrawalId = null): float
    {
        return round((float) BonusWithdrawal::query()->where('bonus_account_id', $accountId)->open()
            ->when($exceptWithdrawalId, fn ($query) => $query->whereKeyNot($exceptWithdrawalId))->sum('amount'), 2);
    }

    public static function available(int $accountId): float
    {
        return round(self::balance($accountId) - self::held($accountId), 2);
    }

    /**
     * Balances for many people at once, for lists: [staff id => balance]. People without an account are 0.
     *
     * @param  list<int>  $staffIds
     * @return array<int, float>
     */
    public static function balancesFor(array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        return BonusAccount::query()->whereIn('bonus_accounts.staff_id', $staffIds)
            ->leftJoin('bonus_transactions', 'bonus_transactions.bonus_account_id', '=', 'bonus_accounts.id')
            ->groupBy('bonus_accounts.staff_id')
            ->selectRaw("bonus_accounts.staff_id, coalesce(sum(case when bonus_transactions.direction = 'credit' then bonus_transactions.amount else -bonus_transactions.amount end), 0) as balance")
            ->pluck('balance', 'bonus_accounts.staff_id')->map(fn ($balance) => round((float) $balance, 2))->all();
    }

    /** A credit by hand, with its reason. @throws BonusRefused */
    public function credit(Staff $person, float $amount, string $reason, Staff $by): BonusTransaction
    {
        self::ensureNotOwn($person->id, $by);

        return DB::transaction(function () use ($person, $amount, $reason, $by) {
            $account = $this->lockedAccount($person);
            $entry = BonusTransaction::query()->create([
                'bonus_account_id' => $account->id, 'direction' => BonusTransaction::CREDIT, 'amount' => $amount,
                'kind' => BonusTransaction::MANUAL, 'reason' => $reason, 'created_by_staff_id' => $by->id,
            ]);
            $this->audit->record('bonus.credited', $by, $person, ['amount' => $amount, 'reason' => $reason, 'entry' => $entry->id]);

            return $entry;
        });
    }

    /** Entries staff may reverse by hand. A commission reversed this way is withheld: CommissionDesk won't credit it again. */
    public const REVERSIBLE = [BonusTransaction::MANUAL, BonusTransaction::COMMISSION, BonusTransaction::VOLUME];

    /**
     * Undoes a credit with an entry the other way. A withdrawal's debit isn't reversed here: its cash-out is, in the cash
     * book. A credit already withdrawn can't be taken back below what is available.
     *
     * @throws BonusRefused
     */
    public function reverse(BonusTransaction $entry, string $reason, Staff $by): BonusTransaction
    {
        return DB::transaction(function () use ($entry, $reason, $by) {
            $account = BonusAccount::query()->whereKey($entry->bonus_account_id)->lockForUpdate()->firstOrFail();
            self::ensureNotOwn($account->staff_id, $by);
            if (! in_array($entry->kind, self::REVERSIBLE, true)) {
                throw new BonusRefused('not_reversible');
            }
            if (BonusTransaction::query()->where('reverses_id', $entry->id)->exists()) {
                throw new BonusRefused('already_reversed');
            }
            if ($entry->direction === BonusTransaction::CREDIT && self::available($account->id) < (float) $entry->amount) {
                throw new BonusRefused('already_withdrawn');
            }

            $reversal = BonusTransaction::query()->create([
                'bonus_account_id' => $account->id,
                'direction' => $entry->direction === BonusTransaction::CREDIT ? BonusTransaction::DEBIT : BonusTransaction::CREDIT,
                'amount' => $entry->amount, 'kind' => BonusTransaction::REVERSAL, 'booking_id' => $entry->booking_id,
                'reverses_id' => $entry->id, 'reason' => $reason, 'created_by_staff_id' => $by->id,
            ]);
            $this->audit->record('bonus.reversed', $by, $account->staff()->first(), ['entry' => $entry->id, 'reversal' => $reversal->id, 'amount' => $entry->amount, 'reason' => $reason]);

            return $reversal;
        });
    }

    /** The staff member asks for money out of their own account. @throws BonusRefused */
    public function request(Staff $person, float $amount, ?string $note): BonusWithdrawal
    {
        if ($amount < self::MIN_WITHDRAWAL) {
            throw new BonusRefused('below_minimum');
        }

        return DB::transaction(function () use ($person, $amount, $note) {
            $account = $this->lockedAccount($person);
            if ($amount > self::available($account->id)) {
                throw new BonusRefused('over_available');
            }
            $withdrawal = BonusWithdrawal::query()->create([
                'bonus_account_id' => $account->id, 'staff_id' => $person->id, 'amount' => $amount, 'note' => $note, 'status' => BonusWithdrawal::PENDING,
            ]);
            $this->event($withdrawal, 'requested', $person, $note);

            return $withdrawal;
        });
    }

    /** The owner withdraws a request nobody has decided yet. @throws BonusRefused */
    public function cancel(BonusWithdrawal $withdrawal, Staff $person): BonusWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $person) {
            $locked = $this->lockedWithdrawal($withdrawal);
            if ($locked->staff_id !== $person->id) {
                throw new BonusRefused('not_yours');
            }
            if ($locked->status !== BonusWithdrawal::PENDING) {
                throw new BonusRefused('already_decided');
            }
            $locked->forceFill(['status' => BonusWithdrawal::CANCELLED])->save();
            $this->event($locked, 'cancelled', $person, null);

            return $locked;
        });
    }

    /** @throws BonusRefused */
    public function approve(BonusWithdrawal $withdrawal, ?string $note, Staff $by): BonusWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $note, $by) {
            $locked = $this->lockedWithdrawal($withdrawal);
            self::ensureNotOwn($locked->staff_id, $by);
            if ($locked->status !== BonusWithdrawal::PENDING) {
                throw new BonusRefused('already_decided');
            }
            // The balance can have fallen since it was asked for (a credit reversed): it must still cover this request.
            BonusAccount::query()->whereKey($locked->bonus_account_id)->lockForUpdate()->firstOrFail();
            if ((float) $locked->amount > self::balance($locked->bonus_account_id) - self::held($locked->bonus_account_id, $locked->id)) {
                throw new BonusRefused('over_available');
            }

            return $this->decide($locked, BonusWithdrawal::APPROVED, $note, $by);
        });
    }

    /** Refuses a pending request, or one approved but not yet paid, with the reason the owner sees. @throws BonusRefused */
    public function reject(BonusWithdrawal $withdrawal, string $note, Staff $by): BonusWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $note, $by) {
            $locked = $this->lockedWithdrawal($withdrawal);
            self::ensureNotOwn($locked->staff_id, $by);
            if (! in_array($locked->status, BonusWithdrawal::OPEN, true)) {
                throw new BonusRefused('already_decided');
            }

            return $this->decide($locked, BonusWithdrawal::REJECTED, $note, $by);
        });
    }

    /**
     * Pays an approved withdrawal: a debit in the bonus ledger and a company cash-out under Staff bonuses, from the money
     * account the method names, with its receipt.
     *
     * @param  array{method: string, reference: ?string, occurred_on: ?string}  $payment
     *
     * @throws BonusRefused
     */
    public function markPaid(BonusWithdrawal $withdrawal, array $payment, ?string $evidencePath, Staff $by): BonusWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $payment, $evidencePath, $by) {
            $locked = $this->lockedWithdrawal($withdrawal);
            self::ensureNotOwn($locked->staff_id, $by);
            if ($locked->status !== BonusWithdrawal::APPROVED) {
                throw new BonusRefused($locked->status === BonusWithdrawal::PAID ? 'already_paid' : 'not_approved');
            }
            BonusAccount::query()->whereKey($locked->bonus_account_id)->lockForUpdate()->firstOrFail();
            if ((float) $locked->amount > self::balance($locked->bonus_account_id)) {
                throw new BonusRefused('over_available');
            }

            $person = $locked->staff()->firstOrFail();
            $cash = $this->ledger->recordManualEntry(
                TransactionDirection::Out, $locked->amount, $payment['method'], 'staff_bonuses', 'office',
                "Bonus withdrawal #{$locked->id} · {$person->name} ({$person->employee_code})", $by, $payment['reference'] ?: null, $evidencePath,
                // As the cash book records a past day: noon in Dhaka.
                filled($payment['occurred_on']) && $payment['occurred_on'] !== now('Asia/Dhaka')->toDateString()
                    ? CarbonImmutable::parse("{$payment['occurred_on']} 12:00", 'Asia/Dhaka')->utc() : null,
            );
            BonusTransaction::query()->create([
                'bonus_account_id' => $locked->bonus_account_id, 'direction' => BonusTransaction::DEBIT, 'amount' => $locked->amount,
                'kind' => BonusTransaction::WITHDRAWAL, 'bonus_withdrawal_id' => $locked->id, 'created_by_staff_id' => $by->id,
            ]);
            $locked->forceFill(['status' => BonusWithdrawal::PAID, 'paid_at' => now(), 'paid_by_staff_id' => $by->id, 'cash_transaction_id' => $cash->id])->save();
            $this->event($locked, 'paid', $by, $payment['reference'] ?: null);
            $this->audit->record('bonus.withdrawal_paid', $by, $person, ['withdrawal' => $locked->id, 'amount' => $locked->amount, 'transaction_id' => $cash->id]);

            return $locked;
        });
    }

    /** The cash book reversed a bonus payout: the money goes back into the account, and the withdrawal back to approved. */
    public function onCashEntryReversed(CashEntryReversed $event): void
    {
        $withdrawal = BonusWithdrawal::query()->where('cash_transaction_id', $event->entry->id)->lockForUpdate()->first();
        if ($withdrawal === null) {
            return;
        }
        $debit = BonusTransaction::query()->where('bonus_withdrawal_id', $withdrawal->id)->where('kind', BonusTransaction::WITHDRAWAL)
            ->whereDoesntHave('reversedBy')->latest('id')->firstOrFail();
        BonusTransaction::query()->create([
            'bonus_account_id' => $withdrawal->bonus_account_id, 'direction' => BonusTransaction::CREDIT, 'amount' => $debit->amount,
            'kind' => BonusTransaction::REVERSAL, 'bonus_withdrawal_id' => $withdrawal->id, 'reverses_id' => $debit->id,
            'reason' => $event->reason, 'created_by_staff_id' => $event->by->id,
        ]);
        $withdrawal->forceFill(['status' => BonusWithdrawal::APPROVED, 'paid_at' => null, 'paid_by_staff_id' => null, 'cash_transaction_id' => null])->save();
        $this->event($withdrawal, 'payment_reversed', $event->by, $event->reason);
        $this->audit->record('bonus.withdrawal_payment_reversed', $event->by, $withdrawal->staff()->first(), [
            'withdrawal' => $withdrawal->id, 'transaction_id' => $event->entry->id, 'reversal_id' => $event->reversal->id, 'reason' => $event->reason,
        ]);
    }

    private function decide(BonusWithdrawal $withdrawal, string $status, ?string $note, Staff $by): BonusWithdrawal
    {
        $withdrawal->forceFill(['status' => $status, 'decided_by_staff_id' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $action = $status === BonusWithdrawal::APPROVED ? 'approved' : 'rejected';
        $this->event($withdrawal, $action, $by, $note);
        $this->audit->record("bonus.withdrawal_{$action}", $by, $withdrawal->staff()->first(), ['withdrawal' => $withdrawal->id, 'amount' => $withdrawal->amount, 'note' => $note]);

        return $withdrawal;
    }

    private function event(BonusWithdrawal $withdrawal, string $action, ?Staff $by, ?string $note): void
    {
        BonusWithdrawalEvent::query()->create(['bonus_withdrawal_id' => $withdrawal->id, 'action' => $action, 'actor_staff_id' => $by?->id, 'note' => $note]);
    }

    private function lockedAccount(Staff $person): BonusAccount
    {
        $this->account($person);

        return BonusAccount::query()->where('staff_id', $person->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedWithdrawal(BonusWithdrawal $withdrawal): BonusWithdrawal
    {
        return BonusWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
    }

    /** @throws BonusRefused */
    private static function ensureNotOwn(int $staffId, Staff $by): void
    {
        if ($staffId === $by->id && ! $by->isSuperAdmin()) {
            throw new BonusRefused('own_bonus');
        }
    }
}
