<?php

namespace App\Services\Payments;

use RuntimeException;

/** A payment request the booking or the request's state doesn't allow. The reason is a lang key under payments.requests.* and the API's `code`. */
final class PaymentRequestRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct($reason);
    }
}
