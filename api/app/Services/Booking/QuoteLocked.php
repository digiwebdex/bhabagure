<?php

namespace App\Services\Booking;

use App\Models\Booking;
use RuntimeException;

/** The booking's invoice is issued: its prices are frozen. Void the invoice to change them. */
class QuoteLocked extends RuntimeException
{
    public function __construct(public readonly Booking $booking)
    {
        parent::__construct("Booking {$booking->reference} has an issued invoice; void it to change the quote.");
    }
}
