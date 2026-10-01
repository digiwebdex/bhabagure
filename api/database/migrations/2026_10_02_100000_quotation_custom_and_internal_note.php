<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations (client, 2026-10-02; docs/quotation-custom-and-notes.md):
 * - a custom quotation, for a trip that isn't one of the website's packages: its own title, a details text printed under
 *   it, and its own lines at a price per person; it converts to a custom service booking;
 * - an internal note for staff only, and `notes` the note for the customer, printed on the quotation.
 *
 * The admin labelled `notes` "Internal note — for the office only, not printed or sent", yet it was printed on the PDF
 * and the customer's link. What staff wrote there they meant as private, so it moves to the internal note (the client's
 * decision); the customer note starts empty. QuotationView::TEMPLATE_VERSION 4 makes every cached PDF render again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('departure_id');
            $table->text('package_details')->nullable()->after('package_code');
            $table->text('internal_note')->nullable()->after('notes');
        });
        DB::table('quotations')->whereNotNull('notes')->update(['internal_note' => DB::raw('notes'), 'notes' => null]);
    }

    public function down(): void
    {
        DB::table('quotations')->whereNull('notes')->whereNotNull('internal_note')->update(['notes' => DB::raw('internal_note')]);
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['is_custom', 'package_details', 'internal_note']);
        });
    }
};
