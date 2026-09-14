<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One punch as the device recorded it (docs/phase-7-hr-attendance-bonus-wallet.md §5.2). `punched_at` is the device's
 * own clock — office time — kept as a plain string so no timezone conversion ever touches it. Unique on device, device
 * user and time: the agent may send it any number of times. Append-only; a wrong day is fixed with a correction.
 */
class AttendancePunch extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['attendance_device_id', 'device_user_id', 'punched_at', 'work_date', 'verify_type', 'punch_state'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}
