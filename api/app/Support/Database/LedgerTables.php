<?php

namespace App\Support\Database;

/**
 * Append-only tables: rows are inserted once and never updated or deleted. Corrections are new rows
 * (reversing entries).
 *
 * Enforced in the application (database triggers need a server-wide MySQL setting the shared host doesn't
 * allow — see DatabaseGuardTriggers):
 *   1. models on these tables use Models\Concerns\AppendOnly (model events + a builder without update/delete);
 *   2. LedgerQueryGuard rejects UPDATE / DELETE / TRUNCATE / REPLACE / upserts against them on every
 *      connection, which also covers DB::table() and raw SQL sent from this app;
 *   3. no route updates or deletes them (tests/Feature/LedgerImmutabilityTest).
 *
 * Tables are listed before they exist, so the rule is already in force when their migration lands.
 * The private wallet's ledger tables (Phase 7, separate database) must be added here when they are named.
 */
final class LedgerTables
{
    public const TABLES = [
        'transactions',
        'bonus_transactions',
        'audit_logs',
        'journal_entries',
        'journal_lines',
        'wallet_transactions',
    ];

    public static function contains(string $table): bool
    {
        return in_array(strtolower(trim($table, '`"')), self::TABLES, true);
    }
}
