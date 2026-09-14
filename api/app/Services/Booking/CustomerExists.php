<?php

namespace App\Services\Booking;

use App\Models\Customer;
use RuntimeException;

/** A "new" customer whose phone number already belongs to a customer record. */
final class CustomerExists extends RuntimeException
{
    public function __construct(public readonly Customer $customer)
    {
        parent::__construct('customer_exists');
    }
}
