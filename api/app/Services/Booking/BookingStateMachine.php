<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\SeatHold;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Invoices\InvoiceIssuer;
use App\Support\WriteScope;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes a booking's status (docs/phase-3-booking.md §3):
 * inquiry → confirmed | cancelled, confirmed → completed | cancelled. completed and cancelled are final.
 */
final class BookingStateMachine
{
    public function __construct(
        private readonly InvoiceIssuer $invoices,
        private readonly AuditLogger $audit,
    ) {}

    /** Needs money on the books: a settled online payment, or a payment staff recorded against the invoice. */
    public function confirm(Booking $booking, ?Staff $staff = null): Booking
    {
        return $this->transition($booking, BookingStatus::Confirmed, $staff, null, function (Booking $locked) {
            if ((float) $locked->paid_amount <= 0) {
                throw new BookingTransitionRefused($locked, 'no_payment');
            }
            $locked->confirmed_at = now();
            SeatHold::query()->where('booking_id', $locked->id)->whereNull('released_at')->whereNull('converted_at')
                ->update(['converted_at' => now()]);
        });
    }

    public function complete(Booking $booking, ?Staff $staff = null): Booking
    {
        return $this->transition($booking, BookingStatus::Completed, $staff, null, function (Booking $locked) {
            $locked->completed_at = now();
        });
    }

    public function cancel(Booking $booking, string $reason, ?Staff $staff = null): Booking
    {
        return $this->transition($booking, BookingStatus::Cancelled, $staff, $reason, function (Booking $locked) use ($reason) {
            if (trim($reason) === '') {
                throw new BookingTransitionRefused($locked, 'reason_required');
            }
            $locked->cancelled_at = now();
            $locked->cancellation_reason = mb_substr($reason, 0, 500);
            SeatHold::query()->where('booking_id', $locked->id)->whereNull('released_at')->update(['released_at' => now()]);
        });
    }

    /** @param callable(Booking): void $apply */
    private function transition(Booking $booking, BookingStatus $to, ?Staff $staff, ?string $reason, callable $apply): Booking
    {
        return DB::transaction(function () use ($booking, $to, $staff, $reason, $apply) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            if ($from === $to) {
                return $locked;
            }
            if (! in_array($to, $from->allowedNext(), true)) {
                throw new BookingTransitionRefused($locked, 'not_allowed', $to);
            }

            $apply($locked);
            $locked->status = $to;
            WriteScope::run(WriteScope::BOOKING_STATUS, fn () => $locked->save());

            if ($to === BookingStatus::Confirmed) {
                $this->invoices->issueForBooking($locked, $staff);
                BookingConfirmed::dispatch($locked);
            }
            if ($to === BookingStatus::Cancelled) {
                BookingCancelled::dispatch($locked);
            }
            $this->audit->record('booking.'.$to->value, $staff, $locked, array_filter(['from' => $from->value, 'reason' => $reason]));

            return $locked->refresh();
        });
    }
}
