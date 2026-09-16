<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Accounts screens (docs/phase-9-accounts.md): staff keep their own chart of accounts beside the ones the software
 * posts to. An account the code names by its number (Account::CASH and the rest) is a system account: it can be renamed
 * but never deleted, and its number and kind never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Named by a constant in App\Models\Account and posted to by the ledger.
            $table->boolean('is_system')->default(false)->after('type');
            // What staff wrote about the account, shown on the chart of accounts.
            $table->string('description', 300)->nullable()->after('is_system');
            $table->timestamp('archived_at')->nullable()->after('description');
            $table->foreignId('created_by_staff_id')->nullable()->after('archived_at')->constrained('staff')->nullOnDelete();
        });

        DB::table('accounts')->whereIn('code', self::systemCodes())->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_staff_id');
            $table->dropColumn(['is_system', 'description', 'archived_at']);
        });
    }

    /** @return list<string> every account number the code refers to by name. */
    private static function systemCodes(): array
    {
        $reflection = new ReflectionClass(Account::class);

        return array_values(array_filter($reflection->getConstants(), fn ($value) => is_string($value)));
    }
};
