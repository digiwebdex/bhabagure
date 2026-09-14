<?php

use App\Support\Database\TableGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-5-admin-core.md §4.6 and question 3 (full double entry):
 *
 *  - chart accounts for manual entries: owner's capital and drawings, opening balance equity, other income, deal
 *    sales, and the expense accounts a travel agency's cash book needs;
 *  - `transactions.business_line`: the prototype's "Source" (tours, air ticketing, hotels…), a tag on the cash-book row;
 *  - `opening_balances`: one audited opening balance per money account, append-only like the journal it posts;
 *  - `reference_presets`: saved labels for the reference field;
 *  - `payment_attempts.reviewed_*`: clearing the online payments that need a person to look;
 *  - `invoices.kind` / `title` / `note`: a deal is a standalone invoice for a customer or company, no package.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['3000', "Owner's capital", 'মালিকের মূলধন', 'equity'],
        ['3100', "Owner's drawings", 'মালিকের উত্তোলন', 'equity'],
        ['3900', 'Opening balances', 'প্রারম্ভিক ব্যালেন্স', 'equity'],
        ['4200', 'Other income', 'অন্যান্য আয়', 'income'],
        ['4300', 'Deal and service sales', 'ডিল ও সার্ভিস বিক্রয়', 'income'],
        ['5000', 'Tour costs and suppliers', 'ট্যুর খরচ ও সরবরাহকারী', 'expense'],
        ['5200', 'Office rent', 'অফিস ভাড়া', 'expense'],
        ['5210', 'Salaries and wages', 'বেতন ও মজুরি', 'expense'],
        ['5220', 'Utilities and internet', 'ইউটিলিটি ও ইন্টারনেট', 'expense'],
        ['5230', 'Marketing', 'মার্কেটিং', 'expense'],
        ['5290', 'Other expenses', 'অন্যান্য খরচ', 'expense'],
    ];

    public function up(): void
    {
        DB::table('accounts')->insertOrIgnore(array_map(fn (array $row) => [
            'code' => $row[0], 'name_en' => $row[1], 'name_bn' => $row[2], 'type' => $row[3], 'created_at' => now(), 'updated_at' => now(),
        ], self::ACCOUNTS));

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('business_line', 30)->nullable()->after('category');
            $table->index(['method', 'occurred_at']);
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('closed_at');
            $table->foreignId('reviewed_by_staff_id')->nullable()->after('reviewed_at')->constrained('staff')->nullOnDelete();
            $table->string('review_note', 300)->nullable()->after('reviewed_by_staff_id');
        });

        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            // One per money account, for ever: a wrong one is corrected with a balance adjustment entry.
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('as_of');
            $table->string('note', 300)->nullable();
            // Null for a zero opening balance: nothing to post.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by_staff_id')->constrained('staff')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        TableGuards::check('opening_balances', 'opening_balances_amount_non_negative', '`amount` >= 0');

        Schema::create('reference_presets', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            // in · out · null for both
            $table->string('direction', 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->unique('label');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // booking · deal
            $table->string('kind', 10)->default('booking')->after('invoice_number');
            $table->string('title', 255)->nullable()->after('billed_address');
            $table->string('note', 500)->nullable()->after('title');
            // A deal can be billed to a company (clients) with no customer record.
            $table->foreignId('customer_id')->nullable()->change();
            $table->index(['kind', 'status']);
        });
        TableGuards::check('invoices', 'invoices_billed_party', '`customer_id` IS NOT NULL OR `client_id` IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `invoices` DROP CHECK `invoices_billed_party`');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn(['kind', 'title', 'note']);
            $table->foreignId('customer_id')->nullable(false)->change();
        });
        Schema::dropIfExists('reference_presets');
        Schema::dropIfExists('opening_balances');
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_staff_id');
            $table->dropColumn(['reviewed_at', 'review_note']);
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['method', 'occurred_at']);
            $table->dropColumn('business_line');
        });
        DB::table('accounts')->whereIn('code', array_column(self::ACCOUNTS, 0))->delete();
    }
};
