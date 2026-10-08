<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal wallets (docs/personal-wallets.md, 2026-10-09): the super admin gives a wallet to staff they choose, and each
 * person's book is theirs alone. Run with --database=wallet. A staff id here is a plain number, as before: the wallet user
 * has no grant on the company database.
 *
 * Entries and deals already carry who made them, and only a wallet's owner ever writes to it, so `created_by_staff_id`
 * is their owner: the append-only ledger is not rewritten. Sources and saved references gain an owner.
 */
return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        $schema = Schema::connection('wallet');
        $db = DB::connection('wallet');

        // Who may open a wallet besides the super admin; a revoked row stays, so the history of access is kept.
        $schema->create('wallet_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->unsignedBigInteger('granted_by_staff_id');
            $table->timestamp('granted_at');
            $table->unsignedBigInteger('revoked_by_staff_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        foreach (['wallet_sources', 'wallet_reference_presets'] as $table) {
            $schema->table($table, fn (Blueprint $t) => $t->unsignedBigInteger('owner_staff_id')->nullable()->after('id'));
        }
        // Until now the wallet was the super admin's alone: a source they used stays theirs.
        foreach ($db->table('wallet_transactions')->whereNotNull('source_id')->select('source_id', 'created_by_staff_id')->distinct()->get() as $use) {
            $db->table('wallet_sources')->where('id', $use->source_id)->whereNull('owner_staff_id')->update(['owner_staff_id' => $use->created_by_staff_id]);
        }
        // Sources nobody used (the two built-in ones) and saved references have no owner to give: each wallet starts with
        // its own Personal and Other instead (App\Wallet\Models\Source::ensureDefaults).
        $db->table('wallet_sources')->whereNull('owner_staff_id')->delete();
        $db->table('wallet_reference_presets')->whereNull('owner_staff_id')->delete();

        $schema->table('wallet_sources', function (Blueprint $t) {
            $t->unsignedBigInteger('owner_staff_id')->nullable(false)->change();
            $t->dropUnique('wallet_sources_name_unique');
            $t->unique(['owner_staff_id', 'name']);
        });
        $schema->table('wallet_reference_presets', function (Blueprint $t) {
            $t->unsignedBigInteger('owner_staff_id')->nullable(false)->change();
            $t->dropUnique('wallet_reference_presets_direction_label_unique');
            $t->unique(['owner_staff_id', 'direction', 'label']);
        });
        // Every screen reads one person's book.
        $schema->table('wallet_transactions', fn (Blueprint $t) => $t->index(['created_by_staff_id', 'occurred_on', 'id']));
        $schema->table('wallet_deals', fn (Blueprint $t) => $t->index('created_by_staff_id'));
    }

    public function down(): void
    {
        $schema = Schema::connection('wallet');
        $schema->table('wallet_deals', fn (Blueprint $t) => $t->dropIndex(['created_by_staff_id']));
        $schema->table('wallet_transactions', fn (Blueprint $t) => $t->dropIndex(['created_by_staff_id', 'occurred_on', 'id']));
        $schema->table('wallet_reference_presets', function (Blueprint $t) {
            $t->dropUnique(['owner_staff_id', 'direction', 'label']);
            $t->unique(['direction', 'label']);
            $t->dropColumn('owner_staff_id');
        });
        $schema->table('wallet_sources', function (Blueprint $t) {
            $t->dropUnique(['owner_staff_id', 'name']);
            $t->unique('name');
            $t->dropColumn('owner_staff_id');
        });
        $schema->dropIfExists('wallet_members');
    }
};
