<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supplier's confirmation voucher or contract for an upcoming booking (docs/booking-vouchers.md). */
class BookingVoucher extends Model
{
    protected $fillable = ['title', 'booking_id', 'service_date', 'disk', 'path', 'mime', 'bytes', 'original_name', 'uploaded_by_staff_id'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'bytes' => 'integer', 'archived_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by_staff_id')->withTrashed();
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'archived_by_staff_id')->withTrashed();
    }
}
