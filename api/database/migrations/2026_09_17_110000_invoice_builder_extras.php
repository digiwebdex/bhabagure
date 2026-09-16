<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the invoice screen still needed (docs/phase-9-accounts.md §5): the customer's own order number, and a delivery
 * charge for sending tickets, passports or visas across — neither belongs to a line, both belong on the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('po_number', 60)->nullable()->after('title');
            $table->decimal('delivery_charge', 12, 2)->default(0)->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['po_number', 'delivery_charge']);
        });
    }
};
