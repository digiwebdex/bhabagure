<?php

namespace App\Exceptions;

use LogicException;

/** An attempt to change or delete a ledger row. Always a programming error: post a reversing entry instead. */
class LedgerImmutable extends LogicException
{
    public function __construct(public readonly string $table)
    {
        parent::__construct("{$table} is append-only: post a reversing entry instead of updating or deleting.");
    }
}
