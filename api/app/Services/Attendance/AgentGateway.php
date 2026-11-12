<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceSyncEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the office agent sends (docs/phase-7-hr-attendance-bonus-wallet.md §5.2): a check-in every minute, a device report
 * after every pull, and punches in batches. Replays are harmless by construction — a punch is unique on device, device
 * user and device time, and the insert ignores what is already there — so the agent may resend anything, any number of
 * times, and the counts say what was new.
 */
final class AgentGateway
{
    public const PULL_INTERVAL_MINUTES = 15;

    public const BATCH_MAX = 500;

    /** Earlier than this is a reset device clock (they fall back to 2000-01-01 and similar), not a real punch. */
    public const EARLIEST_PUNCH = '2015-01-01 00:00:00';

    /** A replacement confirmed by an admin binds the next serial reported within this many days. */
    public const REPLACEMENT_DAYS = 7;

    /** IDs only punches have named (no listing yet) get a row to be matched: this many new ones a batch, ... */
    public const NEW_PUNCH_ONLY_IDS_PER_BATCH = 20;

    /** ... and none once this many such rows wait unlisted. */
    public const NEVER_LISTED_IDS_CAP = 200;

    /**
     * Every minute. The reply hands the agent the device's address and port as set on the device card (2026-10-03), so a
     * change there reaches the office PC within a minute; a null address leaves the agent on the one in its agent.ini.
     *
     * @param  array{agent_version?: ?string}  $data
     * @return array{office_time: string, pull_interval_minutes: int, command: array{type: string, requested_at: string}|null, device: array{address: string|null, port: int}}
     */
    public function checkIn(AttendanceDevice $device, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($device, $data, $ip) {
            $locked = AttendanceDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['last_check_in_at' => now(), 'agent_version' => $data['agent_version'] ?? $locked->agent_version]);
            // Once: an agent that checks in but never reads the device alerts an hour after this (attendance:watch-devices).
            $locked->first_check_in_at ??= now();

            $command = null;
            if ($locked->pending_command !== null) {
                if ($locked->command_requested_at?->gt(now()->subMinutes(AttendanceDevice::COMMAND_PICKUP_MINUTES))) {
                    $command = ['type' => $locked->pending_command, 'requested_at' => $locked->command_requested_at->toIso8601String()];
                    $locked->command_sent_at ??= now();
                } else {
                    AttendanceSyncEvent::query()->create(['attendance_device_id' => $locked->id, 'kind' => AttendanceSyncEvent::COMMAND, 'status' => 'expired', 'detail' => ['command' => $locked->pending_command], 'ip' => $ip]);
                    $locked->forceFill(['pending_command' => null, 'command_requested_at' => null, 'command_sent_at' => null, 'command_requested_by_staff_id' => null]);
                }
            }
            $locked->save();

            return [
                'office_time' => now('Asia/Dhaka')->format('Y-m-d H:i:s'),
                'pull_interval_minutes' => self::PULL_INTERVAL_MINUTES,
                'command' => $command,
                'device' => ['address' => $locked->device_address, 'port' => (int) $locked->device_port],
            ];
        });
    }

    /**
     * After every pull, whether or not the device answered — or that the agent has no address to try (no_address). A
     * report with the device's user list stamps each listed ID, and its own sync-log row, with one time: the IDs on the
     * device are those stamped at the latest listing (AttendanceDeviceController::users).
     *
     * @param  array{serial?: ?string, model?: ?string, firmware?: ?string, address?: ?string, status: string, error?: ?string, device_time?: ?string, pc_time?: ?string, users_count?: ?int, fingers_count?: ?int, records_count?: ?int, users?: list<array{id: string, name?: ?string}>|null, command?: array{type: string, result: string, detail?: ?string}|null}  $data
     * @return array{clock_offset_seconds: int|null}
     *
     * @throws AttendanceRefused
     */
    public function report(AttendanceDevice $device, array $data, ?string $ip): array
    {
        $serial = $data['serial'] ?? null;
        // Checked before the transaction, so the refusal stays in the sync log.
        if ($serial !== null && $device->serial_number !== null && $serial !== $device->serial_number && ! $this->replacementAllowed($device)) {
            AttendanceSyncEvent::query()->create(['attendance_device_id' => $device->id, 'kind' => AttendanceSyncEvent::REPORT, 'status' => 'device_changed', 'detail' => ['reported_serial' => $serial], 'ip' => $ip]);

            throw new AttendanceRefused('device_changed');
        }

        return DB::transaction(function () use ($device, $data, $ip, $serial) {
            $locked = AttendanceDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            if ($serial !== null && $locked->serial_number !== null && $serial !== $locked->serial_number) {
                if (! $this->replacementAllowed($locked)) {
                    throw new AttendanceRefused('device_changed');
                }
                $locked->forceFill(['serial_number' => $serial, 'replacement_allowed_at' => null]);
            }
            if ($serial !== null && $locked->serial_number === null) {
                $locked->serial_number = $serial;
            }

            $offset = null;
            if (filled($data['device_time'] ?? null) && filled($data['pc_time'] ?? null)) {
                $offset = (int) CarbonImmutable::parse($data['pc_time'], 'Asia/Dhaka')->diffInSeconds(CarbonImmutable::parse($data['device_time'], 'Asia/Dhaka'), false);
            }
            $ok = $data['status'] === AttendanceDevice::STATUS_OK;
            $now = now();
            // How many IDs the device listed, when this report carries its user list (only a good pull reads it). An empty
            // list from a device that says it has users is a read that came back short, not an empty device: taken as a
            // listing it would mark every enrolled ID "not on the device any more".
            $users = $ok && is_array($data['users'] ?? null) ? $data['users'] : null;
            $listed = $users !== null && ($users !== [] || (int) ($data['users_count'] ?? 0) === 0) ? count($users) : null;

            $locked->forceFill(array_filter([
                'model' => $data['model'] ?? null,
                'firmware' => $data['firmware'] ?? null,
                'reported_address' => $data['address'] ?? null,
                'users_count' => $data['users_count'] ?? null,
                'fingers_count' => $data['fingers_count'] ?? null,
                'records_count' => $data['records_count'] ?? null,
                'clock_offset_seconds' => $offset,
            ], fn ($value) => $value !== null) + [
                'last_report_at' => $now,
                'last_status' => $data['status'],
                'last_error' => $ok ? null : mb_substr((string) ($data['error'] ?? ''), 0, 300),
            ]);
            if ($ok) {
                $locked->forceFill(['last_pull_ok_at' => $now, 'offline_alerted_at' => null]);
            }

            $command = $data['command'] ?? null;
            if ($command !== null && $command['type'] === $locked->pending_command) {
                AttendanceSyncEvent::query()->create(['attendance_device_id' => $locked->id, 'kind' => AttendanceSyncEvent::COMMAND, 'status' => $command['result'],
                    'detail' => array_filter(['command' => $command['type'], 'detail' => $command['detail'] ?? null]), 'ip' => $ip]);
                $locked->forceFill(['pending_command' => null, 'command_requested_at' => null, 'command_sent_at' => null, 'command_requested_by_staff_id' => null]);
            }
            $locked->save();

            if ($listed !== null) {
                foreach ($data['users'] as $user) {
                    AttendanceDeviceUser::query()->updateOrCreate(
                        ['attendance_device_id' => $locked->id, 'device_user_id' => (string) $user['id']],
                        ['name_on_device' => filled($user['name'] ?? null) ? mb_substr((string) $user['name'], 0, 60) : null, 'last_listed_at' => $now],
                    );
                }
            }

            // Created at the report's own time: a listing's IDs carry exactly this time as last_listed_at.
            AttendanceSyncEvent::query()->forceCreate([
                'attendance_device_id' => $locked->id,
                'kind' => AttendanceSyncEvent::REPORT,
                'status' => $data['status'],
                'detail' => array_filter([
                    'error' => $ok ? null : ($data['error'] ?? null),
                    'clock_offset_seconds' => $offset,
                    'records' => $data['records_count'] ?? null,
                    'users' => $data['users_count'] ?? null,
                    'listed' => $listed,
                ], fn ($value) => $value !== null),
                'ip' => $ip,
                'created_at' => $now,
            ]);

            return ['clock_offset_seconds' => $offset];
        });
    }

    private function replacementAllowed(AttendanceDevice $device): bool
    {
        return $device->replacement_allowed_at !== null && $device->replacement_allowed_at->gte(now()->subDays(self::REPLACEMENT_DAYS));
    }

    /**
     * A batch of punches. Impossible times are refused with a reason and never retried; everything else is inserted
     * unless it is already there. An ID that punches without being in the device's user list (removed from the device
     * since, say) gets a device-user row with no name, so an admin can match it to someone or ignore it; its punches
     * count from the moment it is matched.
     *
     * @param  list<array{user_id: string, time: string, verify?: ?int, state?: ?int}>  $punches
     * @return array{stored: int, duplicates: int, rejected: list<array{index: int, reason: string}>}
     *
     * @throws AttendanceRefused
     */
    public function punches(AttendanceDevice $device, string $serial, array $punches, ?string $ip): array
    {
        if ($device->serial_number === null) {
            throw new AttendanceRefused('report_first');
        }
        if ($serial !== $device->serial_number) {
            throw new AttendanceRefused('device_changed');
        }

        $latest = now('Asia/Dhaka')->addDay()->format('Y-m-d H:i:s');
        $rows = [];
        $rejected = [];
        foreach ($punches as $index => $punch) {
            $reason = match (true) {
                $punch['time'] > $latest => 'future',
                $punch['time'] < self::EARLIEST_PUNCH => 'too_old',
                default => null,
            };
            if ($reason !== null) {
                $rejected[] = ['index' => $index, 'reason' => $reason];

                continue;
            }
            $key = $punch['user_id'].'|'.$punch['time'];
            $rows[$key] ??= [
                'attendance_device_id' => $device->id,
                'device_user_id' => $punch['user_id'],
                'punched_at' => $punch['time'],
                'work_date' => substr($punch['time'], 0, 10),
                'verify_type' => $punch['verify'] ?? null,
                'punch_state' => $punch['state'] ?? null,
                'created_at' => now(),
            ];
        }

        $stored = $rows === [] ? 0 : DB::table('attendance_punches')->insertOrIgnore(array_values($rows));
        $ids = array_values(array_unique(array_column($rows, 'device_user_id')));
        $unknown = $ids === [] ? [] : array_diff($ids, $device->users()->whereIn('device_user_id', $ids)->pluck('device_user_id')->all());
        if ($unknown !== []) {
            // Punches can name IDs no listing has shown yet: each gets a row to be matched. Capped, so that a leaked token
            // can't fill the matching dialog with made-up IDs; the punches themselves are stored all the same, and a later
            // listing adds any real ID that was held back.
            $room = max(0, min(self::NEW_PUNCH_ONLY_IDS_PER_BATCH, self::NEVER_LISTED_IDS_CAP - $device->users()->whereNull('last_listed_at')->count()));
            $unknown = array_slice(array_values($unknown), 0, $room);
        }
        if ($unknown !== []) {
            // Ignored if a report listed the ID meanwhile: unique on device and ID.
            DB::table('attendance_device_users')->insertOrIgnore(array_map(fn (string $id) => [
                'attendance_device_id' => $device->id, 'device_user_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ], $unknown));
        }
        $received = count($punches);
        AttendanceSyncEvent::query()->create([
            'attendance_device_id' => $device->id,
            'kind' => AttendanceSyncEvent::PUNCHES,
            'status' => $rejected === [] ? 'ok' : 'partly_rejected',
            'received' => $received,
            'stored' => $stored,
            'duplicates' => $received - count($rejected) - $stored,
            'rejected' => count($rejected),
            'detail' => $rejected === [] ? null : ['reasons' => array_count_values(array_column($rejected, 'reason'))],
            'ip' => $ip,
        ]);

        return ['stored' => $stored, 'duplicates' => $received - count($rejected) - $stored, 'rejected' => $rejected];
    }
}
