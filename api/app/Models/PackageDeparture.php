<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageDeparture extends Model
{
    protected $fillable = ['tour_package_id', 'departs_on', 'returns_on', 'seats_total', 'is_guaranteed', 'status', 'group_leader_staff_id', 'notes'];

    protected function casts(): array
    {
        return ['departs_on' => 'date', 'returns_on' => 'date', 'is_guaranteed' => 'boolean', 'seats_total' => 'integer'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function groupLeader(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'group_leader_staff_id');
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class, 'departure_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'departure_id');
    }
}
