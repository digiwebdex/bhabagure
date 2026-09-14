<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A biometric device and the office agent that speaks for it (docs/phase-7-hr-attendance-bonus-wallet.md §5.2). The
 * server never reaches the device: everything here is what the agent last reported, plus the one command waiting for
 * its next check-in.
 */
class AttendanceDevice extends Model
{
    public const COMMANDS = ['pull', 'test', 'set_clock'];

    public const STATUS_OK = 'ok';

    public const STATUS_UNREACHABLE = 'device_unreachable';

    public const STATUS_ERROR = 'error';

    /** A command nobody picked up within this long is shown as not picked up (the office PC is probably off). */
    public const COMMAND_PICKUP_MINUTES = 10;

    protected $fillable = ['name', 'created_by_staff_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'token_rotated_at' => 'datetime',
            'replacement_allowed_at' => 'datetime',
            'last_check_in_at' => 'datetime',
            'last_report_at' => 'datetime',
            'last_pull_ok_at' => 'datetime',
            'command_requested_at' => 'datetime',
            'command_sent_at' => 'datetime',
            'offline_alerted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'clock_offset_seconds' => 'integer',
            'users_count' => 'integer',
            'fingers_count' => 'integer',
            'records_count' => 'integer',
        ];
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function users(): HasMany
    {
        return $this->hasMany(AttendanceDeviceUser::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceSyncEvent::class);
    }

    public function commandRequestedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'command_requested_by_staff_id');
    }
}
