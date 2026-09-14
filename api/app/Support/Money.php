<?php

namespace App\Support;

/**
 * Money leaves the API as JSON numbers, never pre-formatted strings; the clients format them with the
 * shared formatter (packages/format). DECIMAL columns arrive from PDO as strings ("75000.00").
 */
final class Money
{
    public static function toNumber(string|int|float|null $amount): int|float|null
    {
        if ($amount === null) {
            return null;
        }

        $value = round((float) $amount, 2);

        return floor($value) === $value ? (int) $value : $value;
    }
}
