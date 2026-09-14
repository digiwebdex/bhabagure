<?php

namespace App\Services\Booking;

use RuntimeException;

class SeatsUnavailable extends RuntimeException
{
    public function __construct(public readonly int $available)
    {
        parent::__construct("Only {$available} seats are left on this departure.");
    }
}
