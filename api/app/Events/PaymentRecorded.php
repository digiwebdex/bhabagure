<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money arrived for a booking, online or recorded by staff. $confirmedBooking: this payment confirmed the booking in the
 * same step — the confirmation message then covers it, so the customer gets one message for one payment.
 */
class PaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly Transaction $payment,
        public readonly bool $confirmedBooking,
    ) {}
}
