<?php

namespace App\Services\Quotations;

use RuntimeException;

/** A quotation action its current state doesn't allow. The reason is a lang key under quotations.* and the API's `code`. */
final class QuotationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
