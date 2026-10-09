<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit and Delete in the wallet (docs/wallet-edits.md). The money ledger stays append-only: an edit cancels the entry
 * with a reversal on its own date and records the corrected entry, which points back at it here; a delete is the
 * reversal alone. A deal is not ledger, so it is marked deleted in place, with its money cancelled. Run with
 * --database=wallet.
 */
return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        $schema = Schema::connection('wallet');

        $schema->table('wallet_transactions', function (Blueprint $table) {
            // The entry this one corrects: each entry is corrected once at most, and is cancelled by its reversal.
            $table->foreignId('replaces_id')->nullable()->unique()->after('reverses_id')->constrained('wallet_transactions');
        });

        $schema->table('wallet_deals', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('wallet');

        $schema->table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_id');
        });

        $schema->table('wallet_deals', function (Blueprint $table) {
            $table->dropColumn(['deleted_at', 'deleted_reason']);
        });
    }
};
