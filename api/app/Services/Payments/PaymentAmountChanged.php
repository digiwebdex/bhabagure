<?php

namespace App\Services\Payments;

use RuntimeException;

/** The amount to pay online is no longer what the customer was shown (the balance or the charge setting changed). */
class PaymentAmountChanged extends RuntimeException
{
    /** @param array{amount: int|float, chargePercent: int|float, charge: int, total: int|float} $payment */
    public function __construct(public readonly array $payment)
    {
        parent::__construct('The amount to pay changed since it was shown.');
    }
}
