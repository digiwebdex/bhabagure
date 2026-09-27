<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed-departure group tours (docs/fixed-departure-group-tours.md): a package is a group tour, booked only on its
 * scheduled departures at one fixed price with its own single and triple room prices, or a customized trip as before.
 * Bookings and quotations keep the room prices they were priced with, as they keep a grid row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->string('trip_type', 20)->default('customized')->after('departure_mode');
            $table->unsignedTinyInteger('single_supplement_percent')->default(50)->after('trip_type');
            $table->unsignedTinyInteger('triple_discount_percent')->default(0)->after('single_supplement_percent');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('group_tour')->nullable()->after('price_grid');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->json('group_tour')->nullable()->after('price_grid');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn('group_tour'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('group_tour'));
        Schema::table('tour_packages', fn (Blueprint $table) => $table->dropColumn(['trip_type', 'single_supplement_percent', 'triple_discount_percent']));
    }
};
