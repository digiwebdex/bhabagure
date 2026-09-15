<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel-category × traveller price grids (docs/phase-8-visa-quotes-pricing-downloads.md §4.D).
 *
 * - tour_packages.price_grid: {"3": {"1": 95000, "2": 75000, "4": …, "6": …, "10": …}, "4": {…}, "5": {…}}, null for a
 *   package priced the old way (one price and the site-wide group discounts).
 * - bookings and quotations keep the chosen category and that category's row as it was priced, the way list_price is
 *   kept, so a later change of travellers on the draft invoice uses the prices the customer was quoted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->json('price_grid')->nullable()->after('sale_price');
        });
        foreach (['bookings', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->char('hotel_category', 1)->nullable()->after('room_type');
                $table->json('price_grid')->nullable()->after('list_price');
            });
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['hotel_category', 'price_grid']);
            });
        }
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->dropColumn('price_grid');
        });
    }
};
