<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice builder (docs/phase-9-accounts.md §5): an invoice with as many lines as it needs, each with its own
 * discount and VAT, written as a draft first and issued when it is right. A booking's invoice is unaffected — it is
 * still built from the booking itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // `due_on` is already there from the finance tables; these are the builder's own.
            $table->string('footer', 500)->nullable()->after('note');
            $table->foreignId('updated_by_staff_id')->nullable()->after('issued_by_staff_id')->constrained('staff')->nullOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Per line: the discount taken off it, then the VAT its own rate adds.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('unit_price');
            $table->decimal('vat_rate', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('vat_amount', 12, 2)->default(0)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'vat_rate', 'vat_amount']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by_staff_id');
            $table->dropColumn('footer');
        });
    }
};
