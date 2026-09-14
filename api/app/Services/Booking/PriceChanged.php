<?php

namespace App\Services\Booking;

use RuntimeException;

/** The server's quote differs from the total the customer saw (a price changed while the modal was open). */
class PriceChanged extends RuntimeException
{
    /** @param array<string, mixed> $quote */
    public function __construct(public readonly array $quote)
    {
        parent::__construct('The price changed since it was shown.');
    }
}
