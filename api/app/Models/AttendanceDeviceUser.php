<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user enrolled on a device — its ID and the name typed on the device, never a fingerprint — matched to a staff member
 * by an admin, or ignored (docs/phase-7-hr-attendance-bonus-wallet.md §5.1). Punches find their person through this.
 */
class AttendanceDeviceUser extends Model
{
    protected $fillable = ['attendance_device_id', 'device_user_id', 'name_on_device', 'last_listed_at'];

    protected function casts(): array
    {
        return [
            'ignored_at' => 'datetime',
            'mapped_at' => 'datetime',
            'last_listed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function mappedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'mapped_by_staff_id');
    }
}
