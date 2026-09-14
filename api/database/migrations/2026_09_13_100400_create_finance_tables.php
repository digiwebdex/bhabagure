<?php

use App\Support\Database\TableGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-1-schema.md §3.5 — invoices and the company cash book.
 *
 * Nothing here references, or is referenced by, the private wallet. The wallet lives in its own
 * database with its own MySQL user (Phase 7), so no migration in this app can join the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            // Assigned when the invoice is issued, so abandoned drafts leave no gaps in the sequence.
            $table->string('invoice_number', 20)->nullable()->unique();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();

            // Immutable snapshot of what was billed (Invoice::SNAPSHOT_COLUMNS, frozen once issued).
            // Next season's price change, a renamed package or a
            // customer's new address must not alter an invoice the customer already received.
            $table->string('billed_name', 160);
            $table->string('billed_phone', 15)->nullable();
            $table->string('billed_email', 190)->nullable();
            $table->string('billed_address', 500)->nullable();
            $table->string('package_code', 30)->nullable();
            $table->string('package_title_en', 255)->nullable();
            $table->string('package_title_bn', 255)->nullable();
            $table->date('travel_start')->nullable();
            $table->date('travel_end')->nullable();
            $table->string('booking_reference', 20)->nullable();
            $table->unsignedTinyInteger('package_duration_days')->nullable();
            $table->unsignedTinyInteger('package_duration_nights')->nullable();
            $table->boolean('includes_airfare')->nullable();
            $table->string('sales_agent_name', 120)->nullable();
            // Names and passport numbers as printed, encrypted (it's passport data).
            $table->text('travellers')->nullable();
            $table->unsignedSmallInteger('pax_count')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('subtotal_amount', 12, 2);
            $table->string('discount_label', 120)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);

            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->storedAs('`total_amount` - `paid_amount`');
            $table->string('status', 20)->default('draft');
            $table->string('payment_status', 20)->default('unpaid');
            $table->char('share_token', 40)->unique();
            $table->string('pdf_path', 255)->nullable();
            $table->foreignId('issued_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'payment_status']);
        });

        TableGuards::check('invoices', 'invoices_amounts_non_negative',
            '`subtotal_amount` >= 0 AND `discount_amount` >= 0 AND `vat_amount` >= 0 AND `total_amount` >= 0 AND `paid_amount` >= 0');

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('kind', 20)->default('other');
            $table->string('title_en', 255);
            $table->string('title_bn', 255)->nullable();
            $table->string('detail', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // The company cash book: money that actually moved. Append-only (see LedgerTables).
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);
            $table->decimal('amount', 12, 2);
            $table->string('category', 30);
            $table->string('method', 20);
            $table->string('external_ref', 100)->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('description', 500);
            $table->string('reference_label', 120)->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->dateTime('occurred_at');
            // restrict, not nullOnDelete: a cascading SET NULL would rewrite ledger rows. Staff are soft-deleted anyway.
            $table->foreignId('recorded_by_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            // unique: an entry can be reversed once. The reversal is itself an ordinary, immutable row.
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // A repeated gateway callback cannot double-credit.
            $table->unique(['method', 'external_ref']);
            $table->index(['booking_id', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
        });

        TableGuards::check('transactions', 'transactions_amount_positive', '`amount` > 0');
        TableGuards::check('transactions', 'transactions_direction', "`direction` IN ('in', 'out')");
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
