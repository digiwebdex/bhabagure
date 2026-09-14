<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceSyncEvent;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The admin's side of the devices (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): adding one and its token (shown once,
 * stored as a hash), rotating or revoking the token, commands for the agent's next check-in, confirming a replaced
 * device, and matching device users to staff. Every step is audited.
 */
final class DeviceRegistry
{
    /** Recognisable in a secret scan; 256 random bits after it. */
    public const TOKEN_PREFIX = 'bhatt_';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{0: AttendanceDevice, 1: string} the device and its plain token */
    public function create(string $name, Staff $by): array
    {
        $token = self::newToken();

        $device = DB::transaction(function () use ($name, $by, $token) {
            $device = new AttendanceDevice(['name' => $name, 'created_by_staff_id' => $by->id]);
            $device->forceFill(['token_hash' => AttendanceDevice::tokenHash($token)])->save();
            $this->audit->record('attendance.device_added', $by, $device, ['name' => $name]);

            return $device;
        });

        return [$device, $token];
    }

    /** The old token stops working at once. */
    public function rotateToken(AttendanceDevice $device, Staff $by): string
    {
        $token = self::newToken();

        DB::transaction(function () use ($device, $by, $token) {
            $device->forceFill(['token_hash' => AttendanceDevice::tokenHash($token), 'token_rotated_at' => now(), 'revoked_at' => null])->save();
            AttendanceSyncEvent::query()->create(['attendance_device_id' => $device->id, 'kind' => AttendanceSyncEvent::TOKEN, 'status' => 'rotated']);
            $this->audit->record('attendance.device_token_rotated', $by, $device);
        });

        return $token;
    }

    public function revoke(AttendanceDevice $device, Staff $by): void
    {
        DB::transaction(function () use ($device, $by) {
            $device->forceFill(['revoked_at' => now(), 'pending_command' => null, 'command_requested_at' => null, 'command_sent_at' => null])->save();
            AttendanceSyncEvent::query()->create(['attendance_device_id' => $device->id, 'kind' => AttendanceSyncEvent::TOKEN, 'status' => 'revoked']);
            $this->audit->record('attendance.device_revoked', $by, $device);
        });
    }

    /** Sync now, test link, or set the device clock: picked up at the agent's next check-in, within a minute. @throws AttendanceRefused */
    public function requestCommand(AttendanceDevice $device, string $command, Staff $by): void
    {
        if ($device->revoked_at !== null) {
            throw new AttendanceRefused('device_revoked');
        }
        if ($command === 'set_clock' && $device->last_report_at === null) {
            throw new AttendanceRefused('no_report_yet');
        }

        DB::transaction(function () use ($device, $command, $by) {
            $device->forceFill(['pending_command' => $command, 'command_requested_at' => now(), 'command_sent_at' => null, 'command_requested_by_staff_id' => $by->id])->save();
            AttendanceSyncEvent::query()->create(['attendance_device_id' => $device->id, 'kind' => AttendanceSyncEvent::COMMAND, 'status' => 'requested', 'detail' => ['command' => $command, 'by' => $by->name]]);
            $this->audit->record('attendance.device_command', $by, $device, ['command' => $command]);
        });
    }

    /** The next report may bind a different serial: the device was replaced on purpose. */
    public function allowReplacement(AttendanceDevice $device, Staff $by): void
    {
        $device->forceFill(['replacement_allowed_at' => now()])->save();
        $this->audit->record('attendance.device_replacement_allowed', $by, $device, ['old_serial' => $device->serial_number]);
    }

    /** Matches a device user to a staff member, or marks it ignored (someone who left before this system, a test enrolment). */
    public function mapUser(AttendanceDeviceUser $user, ?Staff $staff, bool $ignored, Staff $by): AttendanceDeviceUser
    {
        $from = $user->staff_id;
        $user->forceFill([
            'staff_id' => $ignored ? null : $staff?->id,
            'ignored_at' => $ignored ? now() : null,
            'mapped_by_staff_id' => $by->id,
            'mapped_at' => now(),
        ])->save();
        $this->audit->record('attendance.device_user_mapped', $by, $user->device, [
            'device_user_id' => $user->device_user_id, 'from_staff_id' => $from, 'to_staff_id' => $user->staff_id, 'ignored' => $ignored,
        ]);

        return $user;
    }

    private static function newToken(): string
    {
        return self::TOKEN_PREFIX.bin2hex(random_bytes(32));
    }
}
