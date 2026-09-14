<?php

namespace App\Enums;

enum TransactionDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function opposite(): self
    {
        return $this === self::In ? self::Out : self::In;
    }
}
