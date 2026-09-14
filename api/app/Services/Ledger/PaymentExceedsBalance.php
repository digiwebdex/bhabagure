<?php

namespace App\Services\Ledger;

use App\Models\Booking;
use App\Models\Invoice;
use RuntimeException;

/** A payment above what is still due on a booking, or on a deal invoice. */
class PaymentExceedsBalance extends RuntimeException
{
    public function __construct(public readonly Booking|Invoice $for)
    {
        parent::__construct($for instanceof Booking
            ? "The payment is more than the balance due on booking {$for->reference}."
            : "The payment is more than the balance due on invoice {$for->invoice_number}.");
    }
}
