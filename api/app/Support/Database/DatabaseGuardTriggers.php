<?php

namespace App\Support\Database;

use App\Models\Invoice;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional database-level enforcement of the ledger and invoice rules, for clients outside this app.
 *
 * OFF by default. With binary logging on (the MySQL 8 default), creating triggers needs SUPER or the
 * server-wide `log_bin_trust_function_creators` setting, which must not be changed on the shared host.
 * The application layer (LedgerTables) enforces the same rules without it.
 *
 * Switch on only once the host approves: set DB_GUARD_TRIGGERS=true, then `php artisan db:guard-triggers install`.
 */
final class DatabaseGuardTriggers
{
    public static function enabled(): bool
    {
        return (bool) config('bhabaghure.database_guard_triggers');
    }

    public static function install(?ConnectionInterface $connection = null): void
    {
        $db = $connection ?? DB::connection();

        foreach (LedgerTables::TABLES as $table) {
            if (Schema::connection($db->getName())->hasTable($table)) {
                self::appendOnly($db, $table);
            }
        }
        self::frozenAfterDraft($db, 'invoices', Invoice::SNAPSHOT_COLUMNS);
        self::frozenWithParent($db, 'invoice_items', 'invoice_id', 'invoices');
    }

    public static function drop(?ConnectionInterface $connection = null): void
    {
        $db = $connection ?? DB::connection();

        foreach ([...LedgerTables::TABLES, 'invoices', 'invoice_items'] as $table) {
            foreach (['append_only', 'frozen'] as $kind) {
                foreach (['insert', 'update', 'delete'] as $event) {
                    $db->unprepared("DROP TRIGGER IF EXISTS `{$table}_{$kind}_{$event}`");
                }
            }
        }
    }

    /** @return list<string> installed guard trigger names */
    public static function installed(?ConnectionInterface $connection = null): array
    {
        $db = $connection ?? DB::connection();

        return array_map(
            fn ($row) => $row->TRIGGER_NAME,
            $db->select("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND (TRIGGER_NAME LIKE '%\\_append\\_only\\_%' OR TRIGGER_NAME LIKE '%\\_frozen\\_%') ORDER BY TRIGGER_NAME"),
        );
    }

    private static function appendOnly(ConnectionInterface $db, string $table): void
    {
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = "{$table}_append_only_".strtolower($event);
            $db->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            $db->unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only: post a reversing entry instead'");
        }
    }

    /** @param list<string> $columns */
    private static function frozenAfterDraft(ConnectionInterface $db, string $table, array $columns): void
    {
        $changed = implode(' OR ', array_map(fn (string $column) => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", $columns));

        $db->unprepared("DROP TRIGGER IF EXISTS `{$table}_frozen_update`");
        $db->unprepared(<<<SQL
            CREATE TRIGGER `{$table}_frozen_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW
            BEGIN
                IF OLD.`status` <> 'draft' AND ({$changed}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}: an issued record is a frozen snapshot; void and reissue instead';
                END IF;
            END
            SQL);

        $db->unprepared("DROP TRIGGER IF EXISTS `{$table}_frozen_delete`");
        $db->unprepared(<<<SQL
            CREATE TRIGGER `{$table}_frozen_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW
            BEGIN
                IF OLD.`status` <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}: an issued record cannot be deleted; void it instead';
                END IF;
            END
            SQL);
    }

    private static function frozenWithParent(ConnectionInterface $db, string $table, string $foreignKey, string $parent): void
    {
        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            $name = "{$table}_frozen_".strtolower($event);
            $db->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            $db->unprepared(<<<SQL
                CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW
                BEGIN
                    IF (SELECT `status` FROM `{$parent}` WHERE `id` = {$row}.`{$foreignKey}`) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}: lines of an issued record are frozen';
                    END IF;
                END
                SQL);
        }
    }
}
