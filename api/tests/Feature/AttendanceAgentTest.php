<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceSyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5.2: the office agent's side of the API. A token speaks for one device and
 * learns nothing back; the first report binds the device's serial; punches are unique on device, user and time, so any
 * replay stores nothing new; impossible times are refused with a reason; commands reach the agent and are cleared by its
 * report.
 */
class AttendanceAgentTest extends TestCase
{
    use RefreshDatabase;

    private const SERIAL = 'K40-0001';

    #[Test]
    public function only_a_current_token_is_accepted_and_it_speaks_for_its_own_device(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);

        $this->postJson('/api/v1/attendance-agent/check-in')->assertUnauthorized()->assertJsonPath('code', 'token_refused');
        $this->withToken('bhatt_'.str_repeat('0', 64))->postJson('/api/v1/attendance-agent/check-in')->assertUnauthorized();
        $this->flushHeaders();

        $reply = $this->agent($token)->postJson('/api/v1/attendance-agent/check-in', ['agent_version' => '1.0.0'])->assertOk()
            ->assertJsonPath('data.pull_interval_minutes', 15)->assertJsonPath('data.command', null);
        // Counts and commands only: nothing about staff comes back.
        $this->assertSame(['office_time', 'pull_interval_minutes', 'command'], array_keys($reply->json('data')));
        $this->assertSame('1.0.0', $device->fresh()->agent_version);

        // Rotating ends the old token at once; revoking ends the new one.
        $rotated = $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/devices/{$device->id}/rotate-token")->assertOk()->json('token');
        $this->flushHeaders();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertUnauthorized();
        $this->agent($rotated)->postJson('/api/v1/attendance-agent/check-in')->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/devices/{$device->id}/revoke")->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->flushHeaders();
        $this->agent($rotated)->postJson('/api/v1/attendance-agent/check-in')->assertUnauthorized();

        // The token is stored hashed, and never appears in a device listing.
        $this->assertNotSame($rotated, AttendanceDevice::query()->value('token_hash'));
        $this->assertStringNotContainsString('bhatt_', $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/devices')->getContent());
    }

    #[Test]
    public function the_first_report_binds_the_serial_and_another_device_is_refused_until_a_replacement_is_confirmed(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);

        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report())->assertOk()->assertJsonPath('data.clock_offset_seconds', 125);
        $device->refresh();
        $this->assertSame(self::SERIAL, $device->serial_number);
        $this->assertSame(3, $device->users()->count());
        $this->assertSame('ok', $device->last_status);
        $this->assertNotNull($device->last_pull_ok_at);

        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['serial' => 'K40-OTHER']))->assertConflict()->assertJsonPath('code', 'device_changed');
        $this->assertSame(self::SERIAL, $device->fresh()->serial_number);
        $this->assertTrue(AttendanceSyncEvent::query()->where('status', 'device_changed')->exists(), 'the refusal stays in the sync log');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/devices/{$device->id}/allow-replacement")->assertOk();
        $this->flushHeaders();
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['serial' => 'K40-OTHER']))->assertOk();
        $this->assertSame('K40-OTHER', $device->fresh()->serial_number);

        // An unreachable device keeps the last good pull and records the error.
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', ['status' => 'device_unreachable', 'error' => 'timed out', 'serial' => null])->assertOk();
        $this->assertSame('device_unreachable', $device->fresh()->last_status);
        $this->assertSame('timed out', $device->fresh()->last_error);
    }

    #[Test]
    public function a_replayed_or_overlapping_batch_stores_nothing_new_and_impossible_times_are_refused(): void
    {
        $admin = $this->staff('admin');
        [, $token] = $this->device($admin);

        $batch = [
            ['user_id' => '7', 'time' => '2026-09-14 10:58:12', 'verify' => 1, 'state' => 0],
            ['user_id' => '7', 'time' => '2026-09-14 19:03:40', 'verify' => 1, 'state' => 1],
            ['user_id' => '9', 'time' => '2026-09-14 11:21:05', 'verify' => 1, 'state' => 0],
        ];
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => $batch])
            ->assertConflict()->assertJsonPath('code', 'report_first');

        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report())->assertOk();
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => $batch])->assertOk()
            ->assertExactJson(['data' => ['stored' => 3, 'duplicates' => 0, 'rejected' => []]]);
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => $batch])->assertOk()
            ->assertJsonPath('data.stored', 0)->assertJsonPath('data.duplicates', 3);

        $overlap = [...array_slice($batch, 1), ['user_id' => '9', 'time' => '2026-09-14 18:59:59'], ['user_id' => '9', 'time' => '2026-09-14 18:59:59'],
            ['user_id' => '9', 'time' => '2000-01-01 08:00:00'], ['user_id' => '9', 'time' => now('Asia/Dhaka')->addDays(3)->format('Y-m-d H:i:s')]];
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => $overlap])->assertOk()
            ->assertJsonPath('data.stored', 1)->assertJsonPath('data.duplicates', 3)
            ->assertJsonPath('data.rejected', [['index' => 4, 'reason' => 'too_old'], ['index' => 5, 'reason' => 'future']]);

        $this->assertSame(4, AttendancePunch::query()->count());
        $this->assertSame('2026-09-14', AttendancePunch::query()->where('device_user_id', '7')->value('work_date'));
        $this->assertSame('2026-09-14 10:58:12', AttendancePunch::query()->where('device_user_id', '7')->orderBy('punched_at')->value('punched_at'));

        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => 'K40-OTHER', 'punches' => $batch])->assertConflict()->assertJsonPath('code', 'device_changed');
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => array_fill(0, 501, $batch[0])])->assertUnprocessable();

        $log = AttendanceSyncEvent::query()->where('kind', 'punches')->orderBy('id')->get();
        $this->assertSame([[3, 3, 0, 0], [3, 0, 3, 0], [6, 1, 3, 2]], $log->map(fn ($event) => [$event->received, $event->stored, $event->duplicates, $event->rejected])->all());
    }

    #[Test]
    public function a_command_reaches_the_next_check_in_and_the_agents_report_clears_it(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report())->assertOk();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/devices/{$device->id}/commands", ['command' => 'set_clock'])->assertOk()->assertJsonPath('data.pending_command', 'set_clock');
        $this->flushHeaders();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.command.type', 'set_clock');
        // Until reported, it's handed out again (the agent may have died mid-run).
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.command.type', 'set_clock');
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['command' => ['type' => 'set_clock', 'result' => 'ok']]))->assertOk();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.command', null);
        $this->assertTrue(AttendanceSyncEvent::query()->where('kind', 'command')->where('status', 'ok')->exists());

        // A command nobody picks up within ten minutes expires, visibly.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/devices/{$device->id}/commands", ['command' => 'pull'])->assertOk();
        $this->travel(11)->minutes();
        $this->flushHeaders();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.command', null);
        $this->assertTrue(AttendanceSyncEvent::query()->where('kind', 'command')->where('status', 'expired')->exists());

        // Commands are attendance.manage's; an accountant who can see the card can't send one.
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/attendance/devices/{$device->id}/commands", ['command' => 'pull'])->assertForbidden();
        $this->actingAsApi($this->staff('accountant'))->getJson('/api/v1/admin/attendance/devices')->assertOk();
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/attendance/devices')->assertForbidden();
    }

    /** @return array{0: AttendanceDevice, 1: string} */
    private function device($admin): array
    {
        $response = $this->actingAsApi($admin)->postJson('/api/v1/admin/attendance/devices', ['name' => 'ZKTeco · Agargaon office'])->assertCreated();
        $this->flushHeaders();
        $this->resetAuthState();

        return [AttendanceDevice::query()->findOrFail($response->json('data.id')), (string) $response->json('token')];
    }

    private function agent(string $token): static
    {
        $this->resetAuthState();

        return $this->withToken($token);
    }

    /** @param array<string, mixed> $overrides */
    private function report(array $overrides = []): array
    {
        return $overrides + [
            'status' => 'ok',
            'serial' => self::SERIAL,
            'model' => 'K40/ID',
            'firmware' => 'Ver 6.60 Apr 28 2018',
            'address' => '192.0.2.10:4370',
            'device_time' => '2026-09-15 10:02:05',
            'pc_time' => '2026-09-15 10:00:00',
            'users_count' => 3,
            'fingers_count' => 6,
            'records_count' => 3,
            'users' => [['id' => '7', 'name' => 'Nabila'], ['id' => '9', 'name' => 'Imran'], ['id' => '12', 'name' => '']],
        ];
    }
}
