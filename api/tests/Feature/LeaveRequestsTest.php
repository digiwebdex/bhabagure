<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\LeaveRequestEvent;
use App\Models\NotificationMessage;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5.1: staff file their own leave and cancel it while pending; attendance.manage
 * approves it (paid or unpaid), rejects it with a reason, records it for someone, or revokes it; nobody decides their own.
 * Each step is an append-only event. And the offline alert: once per outage, during duty hours only.
 */
class LeaveRequestsTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:30:00', 'Asia/Dhaka'));
    }

    #[Test]
    public function staff_file_leave_a_manager_decides_it_and_the_days_count_as_leave(): void
    {
        $admin = $this->staff('admin');
        $tania = $this->staff('sales_agent');
        StaffProfile::query()->create(['staff_id' => $tania->id, 'joined_on' => '2026-01-01']);

        // 2026-09-24 (Thursday) to 26 (Saturday): three calendar days, two working days around Friday the 25th.
        $filed = $this->actingAsApi($tania)->postJson('/api/v1/admin/profile/leave-requests', ['starts_on' => '2026-09-24', 'ends_on' => '2026-09-26', 'reason' => 'Family wedding in Sylhet'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.calendar_days', 3)->assertJsonPath('data.working_days', 2);
        $id = $filed->json('data.id');
        $this->actingAsApi($tania)->postJson('/api/v1/admin/profile/leave-requests', ['starts_on' => '2026-09-26', 'ends_on' => '2026-09-27', 'reason' => 'Overlapping'])
            ->assertConflict()->assertJsonPath('code', 'leave_overlaps');

        // Deciding is attendance.manage's, and never one's own.
        $this->actingAsApi($tania)->postJson("/api/v1/admin/leave-requests/{$id}/approve", ['paid' => true])->assertForbidden();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/leave-requests?status=pending')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/leave-requests/{$id}/reject", ['note' => ''])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/leave-requests/{$id}/approve", ['paid' => false, 'note' => 'Beyond the paid days this year'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.paid', false);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/leave-requests/{$id}/approve", ['paid' => true])->assertConflict()->assertJsonPath('code', 'leave_decided');

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'Asia/Dhaka'));
        $days = collect($this->actingAsApi($tania)->getJson('/api/v1/admin/profile/attendance?month=2026-09')->assertOk()->json('data.days'))->keyBy('date');
        $this->assertSame(['leave', false], [$days['2026-09-24']['status'], $days['2026-09-24']['leave']['paid']]);
        $this->assertSame('off', $days['2026-09-25']['status'], 'a Friday inside the leave is still a day off');
        $totals = $this->actingAsApi($tania)->getJson('/api/v1/admin/profile/attendance?month=2026-09')->json('data.totals');
        $this->assertSame([0, 2], [$totals['leave_paid'], $totals['leave_unpaid']]);

        // Revoked: those days are working days again.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/leave-requests/{$id}/revoke", ['note' => 'Came to work after all'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame('absent', collect($this->actingAsApi($tania)->getJson('/api/v1/admin/profile/attendance?month=2026-09')->json('data.days'))->firstWhere('date', '2026-09-24')['status']);

        $this->assertSame(['filed', 'approved', 'revoked'], LeaveRequestEvent::query()->where('leave_request_id', $id)->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function the_owner_cancels_only_their_own_pending_request_and_nobody_decides_their_own(): void
    {
        $admin = $this->staff('admin');
        $owner = $this->staff('super_admin');
        $imran = $this->staff('sales_agent');
        $other = $this->staff('sales_agent');

        $id = $this->actingAsApi($imran)->postJson('/api/v1/admin/profile/leave-requests', ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-21', 'reason' => 'Passport renewal'])->assertCreated()->json('data.id');
        $this->actingAsApi($other)->postJson("/api/v1/admin/profile/leave-requests/{$id}/cancel")->assertNotFound();
        $this->actingAsApi($imran)->postJson("/api/v1/admin/profile/leave-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAsApi($imran)->postJson("/api/v1/admin/profile/leave-requests/{$id}/cancel")->assertConflict()->assertJsonPath('code', 'leave_decided');

        // An admin's own request needs someone else; the super admin may decide their own.
        $adminsOwn = $this->actingAsApi($admin)->postJson('/api/v1/admin/profile/leave-requests', ['starts_on' => '2026-09-22', 'ends_on' => '2026-09-23', 'reason' => 'Rest'])->json('data.id');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/leave-requests/{$adminsOwn}/approve", ['paid' => true])->assertForbidden()->assertJsonPath('code', 'own_leave');
        $this->actingAsApi($owner)->postJson("/api/v1/admin/leave-requests/{$adminsOwn}/approve", ['paid' => true])->assertOk();

        // Leave recorded for someone: filed by the manager, marked as such.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/leave-requests', ['staff_id' => $other->id, 'starts_on' => '2026-09-28', 'ends_on' => '2026-09-28', 'reason' => 'Called in sick'])
            ->assertCreated()->assertJsonPath('data.filed_for_someone', true)->assertJsonPath('data.filed_by', $admin->name);
        $this->actingAsApi($imran)->postJson('/api/v1/admin/profile/leave-requests', ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'reason' => 'Too long'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    }

    #[Test]
    public function a_silent_device_alerts_once_per_outage_during_duty_hours_naming_what_went_quiet(): void
    {
        $this->sendNotifications();
        $admin = $this->staff('admin');
        $device = new AttendanceDevice(['name' => 'ZKTeco · Agargaon office']);
        $device->forceFill([
            'token_hash' => str_repeat('b', 64), 'serial_number' => 'K40-0001', 'created_at' => now()->subDays(3),
            'last_check_in_at' => now()->subMinutes(1), 'last_pull_ok_at' => now()->subMinutes(70), 'last_status' => 'device_unreachable',
        ])->save();

        $this->artisan('attendance:watch-devices')->assertSuccessful();
        $this->artisan('attendance:watch-devices')->assertSuccessful();
        $alerts = NotificationMessage::query()->where('event', 'attendance_device_offline_alert')->where('channel', 'email')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($admin->email, $alerts->first()->to_address);
        $this->assertStringContainsString('অফিস পিসি ডিভাইসে পৌঁছাতে পারছে না', $alerts->first()->body);

        // A good pull clears it; the next outage — here the PC itself going quiet — alerts again.
        $device->forceFill(['last_pull_ok_at' => now(), 'offline_alerted_at' => null, 'last_status' => 'ok'])->save();
        $this->travel(80)->minutes();
        $device->forceFill(['last_check_in_at' => now()->subMinutes(45)])->save();
        $this->artisan('attendance:watch-devices')->assertSuccessful();
        $this->assertSame(2, NotificationMessage::query()->where('event', 'attendance_device_offline_alert')->where('channel', 'email')->count());
        $this->assertStringContainsString('সাড়া দিচ্ছে না', NotificationMessage::query()->where('event', 'attendance_device_offline_alert')->latest('id')->firstOrFail()->body);

        // After duty hours, and on a Friday, a silent PC is normal.
        $device->forceFill(['offline_alerted_at' => null, 'last_pull_ok_at' => now()->subHours(3)])->save();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 21:00:00', 'Asia/Dhaka'));
        $this->artisan('attendance:watch-devices')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-09-18 12:30:00', 'Asia/Dhaka'));
        $this->artisan('attendance:watch-devices')->assertSuccessful();
        $this->assertSame(2, NotificationMessage::query()->where('event', 'attendance_device_offline_alert')->where('channel', 'email')->count());
    }
}
