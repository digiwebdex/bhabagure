<?php

use App\Support\Database\TableGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-3-booking.md §8 — seat holds, payment attempts, passport scans and the double-entry journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A departure's seats are held while the customer pays; the hold becomes a sold seat or expires.
        Schema::create('seat_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained('package_departures')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedSmallInteger('seats');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['departure_id', 'released_at', 'converted_at', 'expires_at'], 'seat_holds_active_index');
        });

        // One row per try at paying. Operational and mutable; the money itself is only in the ledger.
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('gateway', 20);
            $table->string('tran_id', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('method_hint', 20)->nullable();
            $table->string('status', 20)->default('initiated');
            $table->string('session_key', 100)->nullable();
            $table->string('gateway_url', 500)->nullable();
            $table->string('val_id', 100)->nullable();
            $table->string('bank_tran_id', 100)->nullable();
            $table->string('card_type', 60)->nullable();
            $table->unsignedTinyInteger('risk_level')->nullable();
            // `amount` goes to the booking; `online_charge` is the configured "online payment charge" line the customer
            // saw on the review step. The gateway is asked for exactly amount + online_charge.
            $table->decimal('online_charge', 12, 2)->default(0);
            // What SSLCommerz confirmed the customer paid, and what it will settle to the store after its fee.
            $table->decimal('gateway_amount', 12, 2)->nullable();
            $table->decimal('store_amount', 12, 2)->nullable();
            // The gateway's own fee (expected − store amount): a business cost.
            $table->decimal('gateway_fee', 12, 2)->default(0);
            // Anything the gateway collected above what the customer was shown. Must stay 0; flagged when it isn't.
            $table->decimal('gateway_surcharge', 12, 2)->default(0);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // Last validation or query response, secrets removed.
            $table->json('gateway_response')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['booking_id', 'status']);
        });

        TableGuards::check('payment_attempts', 'payment_attempts_amount_positive', '`amount` > 0');

        // Uploaded passport images for OCR. Encrypted file, deleted after a day unless a booking uses it.
        Schema::create('passport_scans', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->string('ocr_status', 20);
            $table->string('ocr_provider', 20)->nullable();
            $table->foreignId('booking_traveller_id')->nullable()->constrained('booking_travellers')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        // Chart of accounts (minimal; the Accounting screen extends it later).
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name_en', 120);
            $table->string('name_bn', 120);
            $table->string('type', 20);
            $table->timestamps();
        });

        DB::table('accounts')->insert(array_map(fn (array $row) => [
            'code' => $row[0], 'name_en' => $row[1], 'name_bn' => $row[2], 'type' => $row[3], 'created_at' => now(), 'updated_at' => now(),
        ], [
            ['1000', 'Cash in hand', 'হাতে নগদ', 'asset'],
            ['1010', 'Bank', 'ব্যাংক', 'asset'],
            ['1020', 'Mobile wallets (bKash, Nagad)', 'মোবাইল ওয়ালেট (বিকাশ, নগদ)', 'asset'],
            ['1030', 'SSLCommerz clearing', 'SSLCommerz ক্লিয়ারিং', 'asset'],
            ['1100', 'Accounts receivable', 'প্রাপ্য হিসাব', 'asset'],
            ['2100', 'VAT and service charge payable', 'প্রদেয় ভ্যাট ও সার্ভিস চার্জ', 'liability'],
            ['4000', 'Tour package sales', 'ট্যুর প্যাকেজ বিক্রয়', 'income'],
            ['4100', 'Online payment charges collected', 'আদায়কৃত অনলাইন পেমেন্ট চার্জ', 'income'],
            ['5100', 'Payment gateway fees', 'পেমেন্ট গেটওয়ে ফি', 'expense'],
        ]));

        // Double-entry journal. Append-only (LedgerTables): corrections are reversing entries.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('description', 500);
            $table->nullableMorphs('source');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('reverses_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'journal_entry_id']);
        });

        TableGuards::check('journal_lines', 'journal_lines_one_side', '`debit` >= 0 AND `credit` >= 0 AND ((`debit` > 0 AND `credit` = 0) OR (`credit` > 0 AND `debit` = 0))');

        DB::table('document_sequences')->insertOrIgnore([
            ['key' => 'booking', 'prefix' => 'BH', 'next_value' => 1, 'updated_at' => now()],
            ['key' => 'invoice', 'prefix' => 'INV', 'next_value' => 1, 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        foreach (['journal_lines', 'journal_entries', 'accounts', 'passport_scans', 'payment_attempts', 'seat_holds'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('document_sequences')->whereIn('key', ['booking', 'invoice'])->delete();
    }
};
