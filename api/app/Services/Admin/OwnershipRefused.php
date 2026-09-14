<?php

namespace App\Services\Admin;

use RuntimeException;

final class OwnershipRefused extends RuntimeException
{
    /** @param 'not_claimable'|'same_owner'|'cannot_own' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
