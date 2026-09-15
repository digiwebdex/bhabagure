<?php

namespace App\Services\Payroll;

use RuntimeException;

/** A payroll action the month's state doesn't allow. The reason is a lang key under payroll.* and the API's `code`. */
final class PayrollRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
