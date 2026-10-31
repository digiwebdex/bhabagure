<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A trip's costing, as staff enter it in Finance → Trip profit (docs/trip-profit.md): standalone, tied to no booking.
 * Total cost = flight + land package + other; profit = collected − total cost (negative for a loss). Both are worked
 * out here on every save, in whole paisa so no rounding creeps in, whatever a form sent.
 */
class TripProfit extends Model
{
    use SoftDeletes;

    public const TYPES = ['group_tour', 'custom_trip', 'other'];

    public const COSTS = ['flight_cost', 'land_package_cost', 'other_cost'];

    protected $fillable = [
        'title', 'trip_type', 'trip_date', 'pax', 'flight_cost', 'land_package_cost', 'other_cost', 'total_collected_amount', 'notes',
        'created_by_staff_id', 'updated_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'pax' => 'integer',
            'flight_cost' => 'decimal:2',
            'land_package_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_collected_amount' => 'decimal:2',
            'profit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TripProfit $trip) {
            $cost = array_sum(array_map(fn (string $field) => self::paisa($trip->{$field}), self::COSTS));
            $trip->total_cost = self::taka($cost);
            $trip->profit = self::taka(self::paisa($trip->total_collected_amount) - $cost);
        });
    }

    /** "12500.50" → 1250050. */
    public static function paisa(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    /** 1250050 → "12500.50"; −1250050 → "-12500.50". */
    public static function taka(int $paisa): string
    {
        return ($paisa < 0 ? '-' : '').intdiv(abs($paisa), 100).'.'.str_pad((string) (abs($paisa) % 100), 2, '0', STR_PAD_LEFT);
    }
}
