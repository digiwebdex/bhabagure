<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group tour's departures each with their own price, and the one its card shows (client, 2026-10-01;
 * docs/departure-prices.md).
 *
 *  - `price`: the price per person on that date, in place of the package's (sale, else regular) price; the package's room
 *    percentages apply on top as before. Null: the package's price.
 *  - `is_featured`: the departure the website's card shows, with its date and price, and the booking form starts on.
 *    At most one per package (DepartureController); none, or one that has gone or filled up, falls back to the next
 *    date with seats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_departures', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('seats_total');
            $table->boolean('is_featured')->default(false)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('package_departures', function (Blueprint $table) {
            $table->dropColumn(['price', 'is_featured']);
        });
    }
};
