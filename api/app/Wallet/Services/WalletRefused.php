<?php

namespace App\Wallet\Services;

use RuntimeException;

/** A wallet action refused. The reason is a lang key under wallet.* and the API's `code`. */
final class WalletRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct($reason);
    }
}
