<?php

use App\Support\Payments\EmiRates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The EMI rates from SSLCommerz's chart (docs/emi-preview.md), stored once so Admin → Site settings → EMI rates shows
 * them. Only when there is no EMI setting yet: rates staff have edited are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('site_settings')->where('key', EmiRates::SETTING)->exists()) {
            return;
        }
        DB::table('site_settings')->insert([
            'key' => EmiRates::SETTING,
            'value' => json_encode(EmiRates::defaults()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Left in place: it may hold rates staff have edited.
    }
};
