<?php

namespace App\Services\Hr;

use RuntimeException;

/** An HR action the record's state or the rules don't allow. The reason is a lang key under hr.* and the API's `code`. */
final class HrRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
