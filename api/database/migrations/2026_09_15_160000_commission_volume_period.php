<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic commission (docs/phase-7-hr-attendance-bonus-wallet.md §12 step 4): the month a volume bonus is for. The
 * unique key makes a second month-end run for the same person and month impossible, whatever runs it. Other entries
 * leave it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonus_transactions', function (Blueprint $table) {
            // 'YYYY-MM', a Dhaka month.
            $table->char('period', 7)->nullable()->after('rule');
            $table->unique(['bonus_account_id', 'kind', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('bonus_transactions', function (Blueprint $table) {
            $table->dropUnique(['bonus_account_id', 'kind', 'period']);
            $table->dropColumn('period');
        });
    }
};
