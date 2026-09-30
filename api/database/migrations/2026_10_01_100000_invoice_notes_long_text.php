<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice notes without a practical length limit (client, 2026-10-01; docs/phase-9-accounts.md §5).
 *
 *  - `invoices.note` (Notes / Terms on the printed invoice) and `invoices.footer` were VARCHAR(500), while the invoice
 *    form accepted a 1,000-character note: a note of 501 to 1,000 characters could not be saved. Now LONGTEXT; the API
 *    allows Invoice::NOTE_MAX characters, and the printed invoice runs on to further pages.
 *  - A line's `detail` and `note` were VARCHAR(255) under a 300-character rule, so a product described in 256 to 300
 *    characters could not be put on an invoice. Now TEXT, up to InvoiceItem::TEXT_MAX characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->longText('note')->nullable()->change();
            $table->longText('footer')->nullable()->change();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->text('detail')->nullable()->change();
            $table->text('note')->nullable()->change();
        });
    }

    public function down(): void
    {
        // MySQL refuses this while any note is longer than the old columns hold: shorten those first, on purpose.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->change();
            $table->string('footer', 500)->nullable()->change();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('detail', 255)->nullable()->change();
            $table->string('note', 255)->nullable()->change();
        });
    }
};
