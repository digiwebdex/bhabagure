<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceSyncEvent;
use App\Models\AuditLog;
use App\Services\Attendance\AgentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5.2: the office agent's side of the API. A token speaks for one device and
 * learns nothing back but where the device is; the first report binds the device's serial; punches are unique on device,
 * user and time, so any replay stores nothing new; impossible times are refused with a reason; commands reach the agent
 * and are cleared by its report. And, from 2026-10-03: the device's address set on the card reaches the agent, an ID
 * punching without being on the device gets a row to match, the agent hears English, and the card offers the agent's
 * install package.
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
        // In English, whatever the agent asks for: whoever installs it reads this on the office PC.
        $this->withToken('bhatt_'.str_repeat('0', 64))->withHeader('X-Locale', 'bn')->postJson('/api/v1/attendance-agent/check-in')->assertUnauthorized()
            ->assertJsonPath('message', 'This device token isn’t valid. Make a new one with Rotate token on the Attendance screen, and store it on the office PC with agent.exe set-token.');
        $this->flushHeaders();

        $reply = $this->agent($token)->postJson('/api/v1/attendance-agent/check-in', ['agent_version' => '1.0.0'])->assertOk()
            ->assertJsonPath('data.pull_interval_minutes', 15)->assertJsonPath('data.command', null);
        // Counts, commands and where the device is: nothing about staff comes back.
        $this->assertSame(['office_time', 'pull_interval_minutes', 'command', 'device'], array_keys($reply->json('data')));
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

    #[Test]
    public function the_device_address_set_on_the_card_reaches_the_agent_at_its_next_check_in(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);
        $address = "/api/v1/admin/attendance/devices/{$device->id}/address";

        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.device', ['address' => null, 'port' => 4370]);
        $first = $device->fresh()->first_check_in_at;
        $this->assertNotNull($first);
        // Stamped once: later check-ins move only the last one.
        $this->travel(3)->minutes();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk();
        $this->assertTrue($device->fresh()->first_check_in_at->eq($first));
        $this->assertTrue($device->fresh()->last_check_in_at->gt($first));

        // A dotted private IPv4 address only: the device is on the office LAN.
        $this->actingAsApi($admin)->putJson($address, ['address' => '8.8.8.8'])->assertUnprocessable()
            ->assertJsonValidationErrors(['address' => 'a private IPv4 address such as 192.168.10.116']);
        foreach (['172.32.0.1', '11.0.0.1', '192.168.010.116', '192.168.10.256', '192.168.10', '::1', 'device.local', '192.168.10.116:4370'] as $refused) {
            $this->putJson($address, ['address' => $refused])->assertUnprocessable()->assertJsonValidationErrors('address');
        }
        $this->putJson($address, ['address' => '192.168.10.116', 'port' => 0])->assertUnprocessable()->assertJsonValidationErrors('port');
        $this->putJson($address, ['address' => '192.168.10.116', 'port' => 65536])->assertUnprocessable()->assertJsonValidationErrors('port');
        // Only ZKTeco's ports: the office PC is never pointed at another service on the LAN (a router's 80, a share's 445).
        foreach ([22, 80, 445, 4369, 4381] as $port) {
            $this->putJson($address, ['address' => '192.168.10.1', 'port' => $port])->assertUnprocessable()
                ->assertJsonPath('errors.port.0', __('attendance.device_port_range', ['min' => 4370, 'max' => 4380]));
        }
        $this->putJson($address, ['port' => 4370])->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->assertSame(0, AttendanceSyncEvent::query()->where('kind', 'config')->count());

        // The office's K40, on the default port; the agent has it at its next check-in.
        $this->putJson($address, ['address' => '192.168.10.116'])->assertOk()
            ->assertJsonPath('data.device_address', '192.168.10.116')->assertJsonPath('data.device_port', 4370)->assertJsonPath('data.id', $device->id);
        $this->flushHeaders();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.device', ['address' => '192.168.10.116', 'port' => 4370]);

        $this->actingAsApi($admin)->putJson($address, ['address' => '10.0.0.5', 'port' => 4371])->assertOk();
        $this->putJson($address, ['address' => '10.0.0.5', 'port' => '4371'])->assertOk()->assertJsonPath('data.device_port', 4371);
        $this->assertSame(['10.0.0.5', 4371], [$this->getJson('/api/v1/admin/attendance/devices')->json('data.0.device_address'), $this->getJson('/api/v1/admin/attendance/devices')->json('data.0.device_port')]);
        // Cleared: the agent falls back to the address in its own agent.ini.
        $this->putJson($address, ['address' => null])->assertOk()->assertJsonPath('data.device_address', null)->assertJsonPath('data.device_port', 4370);
        $this->flushHeaders();
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk()->assertJsonPath('data.device', ['address' => null, 'port' => 4370]);

        // Each change, and only a change, is in the sync log and the audit log, from and to.
        $changes = [[null, '192.168.10.116:4370'], ['192.168.10.116:4370', '10.0.0.5:4371'], ['10.0.0.5:4371', null]];
        $this->assertSame($changes, AttendanceSyncEvent::query()->where('kind', 'config')->where('status', 'address_set')->orderBy('id')->get()
            ->map(fn (AttendanceSyncEvent $event) => [$event->detail['from'], $event->detail['to']])->all());
        $this->assertSame($changes, AuditLog::query()->where('action', 'attendance.device_address_set')->where('auditable_id', $device->id)->orderBy('id')->get()
            ->map(fn (AuditLog $log) => [$log->changes['from'], $log->changes['to']])->all());
        $this->assertSame(['config', 'address_set'], [
            $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/devices')->json('data.0.events.0.kind'),
            $this->getJson('/api/v1/admin/attendance/devices')->json('data.0.events.0.status'),
        ]);

        // Setting it is attendance.manage's; an accountant who can see the card can't.
        $this->actingAsApi($this->staff('accountant'))->putJson($address, ['address' => '192.168.10.116'])->assertForbidden();
        $this->assertNull($device->fresh()->device_address);
    }

    #[Test]
    public function an_agent_without_a_device_address_reports_so_in_english_and_the_card_shows_it(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in')->assertOk();

        $this->agent($token)->postJson('/api/v1/attendance-agent/device', ['status' => 'no_address', 'error' => 'No device address: none from the server and none in agent.ini.'])
            ->assertOk()->assertJsonPath('data.clock_offset_seconds', null);
        $card = $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/devices')->assertOk();
        $this->assertSame(['no_address', 'no_address', null, null], [$card->json('data.0.state'), $card->json('data.0.last_status'), $card->json('data.0.last_pull_ok_at'), $card->json('data.0.serial_number')]);
        $this->assertSame('No device address: none from the server and none in agent.ini.', $card->json('data.0.last_error'));
        $this->assertSame(['report', 'no_address'], [$card->json('data.0.events.0.kind'), $card->json('data.0.events.0.status')]);

        // Its validation messages are English too, whatever it asks for.
        $this->flushHeaders();
        $this->agent($token)->withHeader('X-Locale', 'bn')->postJson('/api/v1/attendance-agent/device', ['status' => 'asleep'])->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
        $this->assertSame('no_address', $device->fresh()->last_status);
    }

    #[Test]
    public function an_id_punching_without_being_on_the_device_gets_a_row_to_match_and_on_device_follows_the_latest_list(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report())->assertOk();

        // 31 isn't in the device's list (removed from it since, say): its punches are kept, and it gets a row, unnamed.
        // A refused punch makes no row.
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => [
            ['user_id' => '7', 'time' => '2026-09-14 10:58:12'], ['user_id' => '31', 'time' => '2026-09-14 11:02:00'],
            ['user_id' => '31', 'time' => '2026-09-14 19:01:00'], ['user_id' => '44', 'time' => '2000-01-01 08:00:00'],
        ]])->assertOk()->assertJsonPath('data.stored', 3);
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', ['serial' => self::SERIAL, 'punches' => [['user_id' => '31', 'time' => '2026-09-15 10:59:00']]])->assertOk();
        $this->assertSame(['7', '9', '12', '31'], $device->users()->orderBy('id')->pluck('device_user_id')->all());

        $users = fn () => collect($this->actingAsApi($admin)->getJson("/api/v1/admin/attendance/devices/{$device->id}/users")->assertOk()->json('data.users'))->keyBy('device_user_id');
        $listed = $users();
        $this->assertSame([true, true, true, false], $listed->pluck('on_device')->values()->all());
        $this->assertSame([null, null, false], [$listed['31']['name_on_device'], $listed['31']['staff'], $listed['31']['ignored']]);
        $this->assertSame('Nabila', $listed['7']['name_on_device']);

        // Each list replaces the last: 12 left the device. A report without a list changes nothing; nor does an empty list
        // from a device that says it has users (a read that came back short). Only a really empty device empties it.
        $this->travel(1)->minutes();
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['users' => [['id' => '7', 'name' => 'Nabila'], ['id' => '9', 'name' => 'Imran']]]))->assertOk();
        $this->assertSame([true, true, false, false], $users()->pluck('on_device')->values()->all());
        $this->travel(1)->minutes();
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['users' => null]))->assertOk();
        $this->assertSame([true, true, false, false], $users()->pluck('on_device')->values()->all());
        $this->travel(1)->minutes();
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['users' => [], 'users_count' => 3]))->assertOk();
        $this->assertSame([true, true, false, false], $users()->pluck('on_device')->values()->all());
        $this->travel(1)->minutes();
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report(['users' => [], 'users_count' => 0]))->assertOk();
        $this->assertSame([false, false, false, false], $users()->pluck('on_device')->values()->all());
        $this->assertSame(4, $device->users()->count(), 'nobody is forgotten: their punches still need their match');
    }

    #[Test]
    public function ids_only_punches_name_are_capped_so_a_leaked_token_cant_flood_the_matching_list(): void
    {
        $admin = $this->staff('admin');
        [$device, $token] = $this->device($admin);
        $this->agent($token)->postJson('/api/v1/attendance-agent/check-in', ['agent_version' => '1.1.0'])->assertOk();
        $this->agent($token)->postJson('/api/v1/attendance-agent/device', $this->report())->assertOk();
        $batch = fn (int $from, int $count) => ['serial' => self::SERIAL, 'punches' => array_map(
            fn (int $i) => ['user_id' => (string) (1000 + $i), 'time' => '2026-09-14 10:00:00'], range($from, $from + $count - 1))];

        // 30 made-up IDs in one batch: every punch is stored, only 20 new IDs reach the matching list.
        $this->agent($token)->postJson('/api/v1/attendance-agent/punches', $batch(0, 30))->assertOk()->assertJsonPath('data.stored', 30);
        $this->assertSame(20, $device->users()->whereNull('last_listed_at')->count());

        // Batch after batch, never more than 200 unlisted IDs wait.
        for ($from = 30; $from < 400; $from += 30) {
            $this->agent($token)->postJson('/api/v1/attendance-agent/punches', $batch($from, 30))->assertOk();
        }
        $this->assertSame(AgentGateway::NEVER_LISTED_IDS_CAP, $device->users()->whereNull('last_listed_at')->count());
        $this->assertSame(3, $device->users()->whereNotNull('last_listed_at')->count(), 'the listed users are untouched');
    }

    #[Test]
    public function the_agent_package_downloads_from_the_attendance_card_once_the_developer_has_uploaded_it(): void
    {
        Storage::fake('local');
        $admin = $this->staff('admin');
        $accountant = $this->staff('accountant');
        $package = '/api/v1/admin/attendance/agent-package';

        $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/devices')->assertOk()->assertJsonPath('meta.agent_package', null);
        $this->getJson($package)->assertNotFound()->assertJsonPath('code', 'agent_package_missing')
            ->assertJsonPath('message', 'The agent package isn’t on the server yet. The developer uploads it with deploy/upload-agent-package.sh.');

        // As deploy/upload-agent-package.sh leaves it: the zip, and latest.json beside it.
        $zip = 'PK'.str_repeat("\x03\x04agent", 3000);
        Storage::disk('local')->put('attendance-agent/BhabaghureAttendanceAgent-1.1.0.zip', $zip);
        $manifest = ['version' => '1.1.0', 'filename' => 'BhabaghureAttendanceAgent-1.1.0.zip', 'bytes' => strlen($zip), 'sha256' => hash('sha256', $zip), 'uploaded_at' => '2026-10-03T06:00:00Z'];
        Storage::disk('local')->put('attendance-agent/latest.json', json_encode($manifest));

        $this->getJson('/api/v1/admin/attendance/devices')->assertOk()->assertJsonPath('meta.agent_package', array_merge($manifest, ['uploaded_at' => '2026-10-03T06:00:00+00:00']));
        $download = $this->get($package)->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('Content-Disposition', 'attachment; filename="BhabaghureAttendanceAgent-1.1.0.zip"');
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame($zip, $download->streamedContent());

        // Reading the card shows which agent there is; taking it is attendance.manage's.
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/attendance/devices')->assertOk()->assertJsonPath('meta.agent_package.version', '1.1.0');
        $this->getJson($package)->assertForbidden();

        // A half-finished upload, or a manifest naming anything but its own zip in its own folder, hands out nothing.
        Storage::disk('local')->put('.env', 'APP_KEY=base64:secret');
        Storage::disk('local')->put('attendance-agent/other.zip', $zip);
        foreach ([['bytes' => strlen($zip) + 1], ['filename' => '../.env'], ['filename' => 'other.zip'], ['version' => '../../.env', 'filename' => 'BhabaghureAttendanceAgent-../../.env.zip'],
            ['version' => "1.1.0\n", 'filename' => "BhabaghureAttendanceAgent-1.1.0\n.zip"], ['sha256' => 'not-a-hash'], ['uploaded_at' => 'yesterday-ish']] as $bad) {
            Storage::disk('local')->put('attendance-agent/latest.json', json_encode($bad + $manifest));
            $this->actingAsApi($admin)->getJson($package)->assertNotFound()->assertJsonPath('code', 'agent_package_missing');
            $this->getJson('/api/v1/admin/attendance/devices')->assertOk()->assertJsonPath('meta.agent_package', null);
        }
        Storage::disk('local')->put('attendance-agent/latest.json', '{not json');
        $this->getJson($package)->assertNotFound();
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
