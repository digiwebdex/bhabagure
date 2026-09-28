<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room rates for every package (docs/room-rates.md, decided 2026-09-28): triple sharing is the base price, a twin room
 * adds the package's twin percentage and a single room its single percentage. Twin (+%) replaces the group tours'
 * triple discount, which no package used. Customized trips start at single +15% (the client's rule) and twin +0%.
 *
 * Bookings and quotations keep the rates they were priced with (`room_rates`, and `fixed_price` for a group tour);
 * those made before today have none and keep the old rule when staff re-price them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('twin_supplement_percent')->default(0)->after('single_supplement_percent');
            $table->unsignedTinyInteger('single_supplement_percent')->default(15)->change();
        });
        // The group tours' single percentage was their own; a customized trip's was never used until now.
        DB::table('tour_packages')->where('trip_type', 'customized')->update(['single_supplement_percent' => 15]);
        Schema::table('tour_packages', fn (Blueprint $table) => $table->dropColumn('triple_discount_percent'));

        foreach (['bookings', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('room_rates')->nullable()->after('price_grid');
                $table->boolean('fixed_price')->default(false)->after('room_rates');
            });
            DB::table($name)->whereNotNull('group_tour')->orderBy('id')->each(function (object $row) use ($name) {
                $old = json_decode((string) $row->group_tour, true);
                DB::table($name)->where('id', $row->id)->update([
                    'room_rates' => json_encode(['singleSupplementPercent' => (int) ($old['singleSupplementPercent'] ?? 0), 'twinSupplementPercent' => 0]),
                    'fixed_price' => true,
                ]);
            });
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('group_tour'));
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('group_tour')->nullable()->after('price_grid');
                $table->dropColumn(['room_rates', 'fixed_price']);
            });
        }
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('triple_discount_percent')->default(0)->after('single_supplement_percent');
            $table->dropColumn('twin_supplement_percent');
            $table->unsignedTinyInteger('single_supplement_percent')->default(50)->change();
        });
    }
};
