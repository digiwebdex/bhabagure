<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking for a custom service (docs/custom-service-bookings.md): no package, the office's own items, each at a price
 * per person. Its draft invoice is re-priced from those items rather than from a package price and group discounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('tour_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_custom');
        });
    }
};
