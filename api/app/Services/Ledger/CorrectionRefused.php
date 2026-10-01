<?php

namespace App\Services\Ledger;

use RuntimeException;

/** An edit or a delete the cash book can't take, and why (CashBookCorrections::editBlocked / deleteBlocked). */
final class CorrectionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The entry can't be changed: {$reason}");
    }
}
