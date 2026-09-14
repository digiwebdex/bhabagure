<?php

namespace App\Support;

use Closure;

/**
 * Marks a stretch of code as the one place allowed to write certain columns. Models refuse writes to those columns
 * outside the scope — e.g. a booking's status outside BookingStateMachine, or its paid amount outside LedgerService.
 */
final class WriteScope
{
    public const BOOKING_STATUS = 'booking-status';

    public const BOOKING_MONEY = 'booking-money';

    public const INVOICE_STATUS = 'invoice-status';

    /** @var array<string, int> */
    private static array $depth = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $scope, Closure $callback): mixed
    {
        self::$depth[$scope] = (self::$depth[$scope] ?? 0) + 1;
        try {
            return $callback();
        } finally {
            self::$depth[$scope]--;
        }
    }

    public static function active(string $scope): bool
    {
        return (self::$depth[$scope] ?? 0) > 0;
    }
}
