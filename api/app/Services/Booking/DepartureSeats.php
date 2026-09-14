<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\PackageDeparture;
use App\Models\SeatHold;

/** Seats on a departure: sold to confirmed or completed bookings, or held while someone pays. */
final class DepartureSeats
{
    public const SOLD_STATUSES = ['confirmed', 'completed'];

    public static function sold(PackageDeparture $departure): int
    {
        return (int) Booking::query()->where('departure_id', $departure->id)->whereIn('status', self::SOLD_STATUSES)->sum('pax_count');
    }

    public static function held(PackageDeparture $departure): int
    {
        return (int) SeatHold::query()->active()->where('departure_id', $departure->id)->sum('seats');
    }

    public static function available(PackageDeparture $departure): int
    {
        return max(0, (int) $departure->seats_total - self::sold($departure) - self::held($departure));
    }
}
