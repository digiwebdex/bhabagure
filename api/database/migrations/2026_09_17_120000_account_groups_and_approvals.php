<?php

use App\Support\Ledger\AccountGroups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the Accounting screens needed (docs/phase-9-accounts.md §2, §7):
 *
 * · accounts get the section they are read under — "Cash and Bank", "Operating Expense" and the rest. A kind alone is
 *   too broad to find anything in, and these are the sections the client's books already use.
 * · cash book entries get an approval, so whoever owns the money can tick off what staff recorded. An entry is real
 *   from the moment it is posted; the tick only records that someone has since looked at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('group', 40)->nullable()->after('type');
            $table->index(['type', 'group']);
        });

        foreach (AccountGroups::SYSTEM_GROUPS as $code => $group) {
            DB::table('accounts')->where('code', $code)->update(['group' => $group]);
        }
        // Anything staff added before sections existed falls under its kind's usual one.
        foreach (AccountGroups::DEFAULTS as $type => $group) {
            DB::table('accounts')->where('type', $type)->whereNull('group')->update(['group' => $group]);
        }

        Schema::table('transactions', function (Blueprint $table) {
            // Which account the money actually landed in or left. It was only ever implied by the method, which cannot
            // tell the office drawer from a float a staff member carries.
            $table->foreignId('money_account_id')->nullable()->after('method')->constrained('accounts')->nullOnDelete();
            $table->index('money_account_id');
        });

        // Whoever owns the money ticking off what staff recorded. It is a record of its own rather than a column on the
        // entry, because the cash book is append-only: an entry is never edited, not even to say it has been seen.
        Schema::create('transaction_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('approved_by_staff_id')->constrained('staff')->cascadeOnDelete();
            $table->timestamp('approved_at');
            $table->string('note', 300)->nullable();
        });

        // Entries written before this column existed keep a null, and the screens fall back to the account the method
        // has always meant. They are not filled in here: the cash book is append-only and refuses an UPDATE, which is
        // the guarantee that makes it worth reading at all.
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_approvals');
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_account_id');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['type', 'group']);
            $table->dropColumn('group');
        });
    }
};
