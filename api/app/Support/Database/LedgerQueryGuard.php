<?php

namespace App\Support\Database;

use App\Exceptions\LedgerImmutable;
use Closure;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Inspects every SQL statement before it is sent and refuses anything that would change or remove a ledger
 * row. Runs on all connections, so query-builder mass updates, DB::table() and DB::statement() are covered,
 * not just Eloquent models.
 *
 * It cannot stop a different MySQL client (a DBA's console); DatabaseGuardTriggers does that once the host
 * allows triggers.
 */
final class LedgerQueryGuard
{
    private const PATTERNS = [
        '/^\s*update\s+(?:low_priority\s+|ignore\s+)*[`"]?(\w+)[`"]?/i',
        '/^\s*delete\s+(?:low_priority\s+|quick\s+|ignore\s+)*from\s+[`"]?(\w+)[`"]?/i',
        '/^\s*delete\s+[`"]?\w+[`"]?(?:\s*,\s*[`"]?\w+[`"]?)*\s+from\s+[`"]?(\w+)[`"]?/i',
        '/^\s*truncate\s+(?:table\s+)?[`"]?(\w+)[`"]?/i',
        '/^\s*replace\s+(?:low_priority\s+|delayed\s+)?(?:into\s+)?[`"]?(\w+)[`"]?/i',
        '/^\s*insert\s+(?:ignore\s+)?(?:into\s+)?[`"]?(\w+)[`"]?.*\bon\s+duplicate\s+key\s+update\b/is',
    ];

    private static bool $enabled = true;

    /** @var \WeakMap<Connection, true>|null */
    private static ?\WeakMap $guarded = null;

    public static function register(Connection $connection): void
    {
        self::$guarded ??= new \WeakMap;
        if (isset(self::$guarded[$connection])) {
            return;
        }
        self::$guarded[$connection] = true;

        $connection->beforeExecuting(function (string $query): void {
            if (self::$enabled && ($table = self::mutatedLedgerTable($query)) !== null) {
                throw new LedgerImmutable($table);
            }
        });
    }

    /** The ledger table a statement would update or delete from, or null when it is harmless. */
    public static function mutatedLedgerTable(string $sql): ?string
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $sql, $match) === 1 && LedgerTables::contains($match[1])) {
                return strtolower($match[1]);
            }
        }

        return null;
    }

    /**
     * Test-only: lets DatabaseGuardTriggersTest reach the database triggers behind this guard.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutGuard(Closure $callback): mixed
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The ledger guard can only be bypassed inside the test suite.');
        }

        self::$enabled = false;
        try {
            return $callback();
        } finally {
            self::$enabled = true;
        }
    }
}
