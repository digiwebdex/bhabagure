<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatHold extends Model
{
    protected $fillable = ['departure_id', 'booking_id', 'seats', 'expires_at', 'released_at', 'converted_at'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'expires_at' => 'datetime', 'released_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    /** Holding seats right now: not released, not yet sold, not expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->whereNull('converted_at')->where('expires_at', '>', now());
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
