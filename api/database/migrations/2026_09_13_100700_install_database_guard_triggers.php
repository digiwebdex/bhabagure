<?php

use App\Support\Database\DatabaseGuardTriggers;
use Illuminate\Database\Migrations\Migration;

/**
 * Database-level ledger and invoice triggers — only when DB_GUARD_TRIGGERS=true.
 *
 * Off by default: on the shared host, creating triggers would need the server-wide
 * `log_bin_trust_function_creators` setting. The application enforces the same rules (LedgerTables).
 * To switch on after this migration has already run: `php artisan db:guard-triggers install`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DatabaseGuardTriggers::enabled()) {
            DatabaseGuardTriggers::install();
        }
    }

    public function down(): void
    {
        DatabaseGuardTriggers::drop();
    }
};
