<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use RuntimeException;

class BookingTransitionRefused extends RuntimeException
{
    /** @param 'not_allowed'|'no_payment'|'reason_required' $reason */
    public function __construct(public readonly Booking $booking, public readonly string $reason, ?BookingStatus $to = null)
    {
        parent::__construct(match ($reason) {
            'not_allowed' => "Booking {$booking->reference} can't go from {$booking->status->value} to {$to?->value}.",
            'no_payment' => "Booking {$booking->reference} has no recorded payment, so it can't be confirmed.",
            'reason_required' => 'A cancellation needs a reason.',
        });
    }
}
