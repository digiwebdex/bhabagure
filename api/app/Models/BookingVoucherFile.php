<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file a voucher had before staff replaced it (docs/booking-vouchers.md §6); kept, never deleted. */
class BookingVoucherFile extends Model
{
    protected $fillable = ['booking_voucher_id', 'disk', 'path', 'mime', 'bytes', 'original_name', 'uploaded_by_staff_id', 'uploaded_at', 'replaced_by_staff_id'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(BookingVoucher::class, 'booking_voucher_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by_staff_id')->withTrashed();
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'replaced_by_staff_id')->withTrashed();
    }
}
