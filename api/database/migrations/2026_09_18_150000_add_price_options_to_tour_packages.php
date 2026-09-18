<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a package costs on top of its own price: an extra the traveller can choose (a domestic flight, a resort day
 * tour) or a cost the agency only estimates (the international air ticket). docs/package-price-options.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->json('price_options')->nullable()->after('price_grid');
        });
    }

    public function down(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->dropColumn('price_options');
        });
    }
};
