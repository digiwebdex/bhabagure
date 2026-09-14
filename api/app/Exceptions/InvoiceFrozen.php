<?php

namespace App\Exceptions;

use App\Models\Invoice;
use LogicException;

/** An issued invoice is a frozen snapshot of what was billed. Corrections are a void and reissue. */
class InvoiceFrozen extends LogicException
{
    public static function snapshot(Invoice $invoice): self
    {
        $columns = implode(', ', array_keys(array_intersect_key($invoice->getDirty(), array_flip(Invoice::SNAPSHOT_COLUMNS))));

        return new self("Invoice {$invoice->invoice_number} is issued; its billing snapshot ({$columns}) can't change. Void and reissue instead.");
    }

    public static function delete(Invoice $invoice): self
    {
        return new self("Invoice {$invoice->invoice_number} is issued and can't be deleted. Void it instead.");
    }

    public static function lines(int $invoiceId): self
    {
        return new self("The lines of issued invoice #{$invoiceId} can't be added, changed or removed.");
    }
}
