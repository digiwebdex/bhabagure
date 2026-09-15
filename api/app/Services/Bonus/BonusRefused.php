<?php

namespace App\Services\Bonus;

use RuntimeException;

/** A bonus action the rules or the record's state don't allow. The reason is a lang key under bonus.* and the API's `code`. */
final class BonusRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
