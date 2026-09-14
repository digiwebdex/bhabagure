<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An airline ticket issued for one traveller on a booking. Voided, never deleted: a wrong ticket is voided with a reason
 * and the right one recorded. Only App\Services\Documents\BookingTickets writes these rows.
 */
class BookingTicket extends Model
{
    protected $fillable = [
        'booking_id', 'booking_traveller_id', 'airline', 'pnr', 'ticket_number', 'route', 'departs_on', 'disk', 'path', 'mime', 'bytes',
        'issued_by_staff_id', 'voided_at', 'voided_by_staff_id', 'void_reason',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['departs_on' => 'date', 'voided_at' => 'datetime', 'bytes' => 'integer'];
    }

    public function scopeIssued(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('voided_at'));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function traveller(): BelongsTo
    {
        return $this->belongsTo(BookingTraveller::class, 'booking_traveller_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'issued_by_staff_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'voided_by_staff_id');
    }
}
