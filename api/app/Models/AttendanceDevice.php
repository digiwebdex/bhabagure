<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A biometric device and the office agent that speaks for it (docs/phase-7-hr-attendance-bonus-wallet.md §5.2). The
 * server never reaches the device: everything here is what the agent last reported, plus what the agent is handed at
 * its next check-in — the device's network address set on the card, and the one command waiting.
 */
class AttendanceDevice extends Model
{
    public const COMMANDS = ['pull', 'test', 'set_clock'];

    public const STATUS_OK = 'ok';

    public const STATUS_UNREACHABLE = 'device_unreachable';

    public const STATUS_ERROR = 'error';

    /** The agent has no device address: none set on the card, and none in its own agent.ini. */
    public const STATUS_NO_ADDRESS = 'no_address';

    /** What a device report's status may be. */
    public const REPORT_STATUSES = [self::STATUS_OK, self::STATUS_UNREACHABLE, self::STATUS_ERROR, self::STATUS_NO_ADDRESS];

    /** ZKTeco's TCP port, the device's own default. */
    public const DEFAULT_PORT = 4370;

    /** The ports the device card accepts: ZKTeco's, never any port on the LAN (the agent would try what it is given). */
    public const PORT_MIN = 4370;

    public const PORT_MAX = 4380;

    /** A device sits on the office LAN: its address is a private IPv4 one (RFC 1918), never a public one. */
    public const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** A command nobody picked up within this long is shown as not picked up (the office PC is probably off). */
    public const COMMAND_PICKUP_MINUTES = 10;

    protected $fillable = ['name', 'created_by_staff_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'device_port' => 'integer',
            'token_rotated_at' => 'datetime',
            'replacement_allowed_at' => 'datetime',
            'first_check_in_at' => 'datetime',
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

    /** "192.168.10.116:4370", or null while no address is set: how the sync log and the audit log name it. */
    public function addressLabel(): ?string
    {
        return $this->device_address === null ? null : "{$this->device_address}:{$this->device_port}";
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
