<?php

namespace App\Services\Documents;

use RuntimeException;

/** A document action the slot's state doesn't allow. The reason is a lang key under documents.* and the API's `code`. */
final class DocumentRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
