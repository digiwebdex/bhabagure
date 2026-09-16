<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where money sits is now a property of the account, not a fixed list in the code (docs/phase-9-accounts.md §6): the
 * company holds cash in named floats as well as in the office drawer and the bank, and each has to be visible on its
 * own. The six the software posts to are flagged here; staff add their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_money')->default(false)->after('is_system');
        });

        // The accounts the company balance has always counted. The old shared Mobile wallets account is deliberately
        // left out: it holds only a legacy balance and is shown separately while that balance remains.
        DB::table('accounts')->whereIn('code', Account::MONEY)->update(['is_money' => true]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_money');
        });
    }
};
