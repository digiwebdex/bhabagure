<?php

namespace App\Services\Ledger;

use App\Models\Booking;
use RuntimeException;

class PaymentExceedsBalance extends RuntimeException
{
    public function __construct(public readonly Booking $booking)
    {
        parent::__construct("The payment is more than the balance due on booking {$booking->reference}.");
    }
}
