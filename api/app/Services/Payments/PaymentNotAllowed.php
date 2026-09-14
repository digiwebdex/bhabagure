<?php

namespace App\Services\Payments;

use App\Models\Booking;
use RuntimeException;

/** The booking is cancelled, completed or already paid in full. */
class PaymentNotAllowed extends RuntimeException
{
    public function __construct(public readonly Booking $booking)
    {
        parent::__construct("Booking {$booking->reference} can't take an online payment now.");
    }
}
