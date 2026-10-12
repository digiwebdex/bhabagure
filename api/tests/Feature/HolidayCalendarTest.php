<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * docs/holiday-calendar.md: every staff member sees the year's holidays, the weekly days off and their own leave; only
 * holidays.manage — the super admin by default — keeps the calendar. A holiday of several days is one date range, a day
 * per row, never overlapping another; attendance reads each day as before.
 */
class HolidayCalendarTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_super_admin_keeps_the_calendar_in_date_ranges_and_everyone_sees_it(): void
    {
        $owner = $this->staff('super_admin');
        $agent = $this->staff('sales_agent');
        $admin = $this->staff('admin');

        // Nobody else keeps it by default, admins included.
        $eid = ['starts_on' => '2026-03-19', 'ends_on' => '2026-03-23', 'name_en' => 'Eid-ul-Fitr', 'name_bn' => 'ঈদুল ফিতর'];
        $this->actingAsApi($agent)->postJson('/api/v1/admin/holidays', $eid)->assertForbidden();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/holidays', $eid)->assertForbidden();

        // A range is one holiday, a day per row.
        $saved = $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays', $eid)->assertCreated()
            ->assertJsonPath('data.days', 5)->assertJsonPath('data.ends_on', '2026-03-23')->json('data');
        $this->assertSame(5, Holiday::query()->where('group_id', $saved['id'])->count());
        $shaheed = $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays', ['starts_on' => '2026-02-21', 'ends_on' => '2026-02-21', 'name_en' => 'Shaheed Day', 'name_bn' => 'শহীদ দিবস'])
            ->assertCreated()->json('data.id');

        // Never on top of another holiday; 1 to 15 days; the end not before the start.
        $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays', ['starts_on' => '2026-03-23', 'ends_on' => '2026-03-24', 'name_en' => 'Clash', 'name_bn' => 'সংঘাত'])
            ->assertConflict()->assertJsonPath('code', 'holiday_overlap');
        $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays', ['starts_on' => '2026-06-01', 'ends_on' => '2026-06-16', 'name_en' => 'Too long', 'name_bn' => 'অনেক লম্বা'])
            ->assertConflict()->assertJsonPath('code', 'holiday_too_long');
        $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays', ['starts_on' => '2026-06-02', 'ends_on' => '2026-06-01', 'name_en' => 'Backwards', 'name_bn' => 'উল্টো'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');

        // Changed: the days are replaced, its own days don't count as a clash, another holiday's do.
        $this->actingAsApi($owner)->putJson("/api/v1/admin/holidays/{$saved['id']}", ['starts_on' => '2026-03-20', 'ends_on' => '2026-03-22'] + $eid)
            ->assertOk()->assertJsonPath('data.days', 3)->assertJsonPath('data.starts_on', '2026-03-20');
        $this->assertSame(['2026-03-20', '2026-03-21', '2026-03-22'], Holiday::query()->where('group_id', $saved['id'])->orderBy('date')->pluck('date')->map->toDateString()->all());
        $this->actingAsApi($owner)->putJson("/api/v1/admin/holidays/{$saved['id']}", ['starts_on' => '2026-02-21', 'ends_on' => '2026-02-22'] + $eid)
            ->assertConflict()->assertJsonPath('code', 'holiday_overlap');

        // Everyone sees the year: holidays once each, Fridays off, their own leave and nobody else's.
        LeaveRequest::query()->create(['staff_id' => $agent->id, 'starts_on' => '2026-05-04', 'ends_on' => '2026-05-05', 'reason' => 'Family', 'status' => LeaveRequest::APPROVED, 'paid' => true, 'filed_by_staff_id' => $agent->id]);
        LeaveRequest::query()->create(['staff_id' => $agent->id, 'starts_on' => '2026-06-10', 'ends_on' => '2026-06-10', 'reason' => 'Doctor', 'status' => LeaveRequest::PENDING, 'filed_by_staff_id' => $agent->id]);
        LeaveRequest::query()->create(['staff_id' => $agent->id, 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-01', 'reason' => 'No', 'status' => LeaveRequest::REJECTED, 'filed_by_staff_id' => $agent->id]);
        LeaveRequest::query()->create(['staff_id' => $admin->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-12', 'reason' => 'Trip', 'status' => LeaveRequest::APPROVED, 'paid' => true, 'filed_by_staff_id' => $admin->id]);
        $year = $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/holidays?year=2026')->assertOk()->json('data');
        $this->assertSame(['Shaheed Day', 'Eid-ul-Fitr'], array_column($year['holidays'], 'name_en'));
        $this->assertSame(4, $year['holiday_days']);
        $this->assertCount(12, $year['weekly_off_days']);
        $this->assertSame([5], $year['weekly_off_days'][0]);
        $this->assertSame([['2026-05-04', 'approved'], ['2026-06-10', 'pending']], array_map(fn (array $leave) => [$leave['starts_on'], $leave['status']], $year['my_leave']));
        $this->assertFalse($year['can_manage']);
        $this->assertTrue($this->actingAsApi($owner)->getJson('/api/v1/admin/profile/holidays?year=2026')->json('data.can_manage'));

        // Attendance reads every day of the range as a holiday, as before.
        $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/month?month=2026-03')->assertOk()->assertJsonCount(3, 'data.holidays');

        // Copied to next year onto the same days; a second copy is skipped and named.
        $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays/copy', ['from_year' => 2026, 'to_year' => 2027, 'holidays' => [$shaheed]])
            ->assertOk()->assertJsonPath('data.copied', 1)->assertJsonPath('data.skipped', []);
        $this->actingAsApi($owner)->postJson('/api/v1/admin/holidays/copy', ['from_year' => 2026, 'to_year' => 2027, 'holidays' => [$shaheed]])
            ->assertOk()->assertJsonPath('data.copied', 0)->assertJsonPath('data.skipped', ['Shaheed Day']);
        $this->assertSame('2027-02-21', $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/holidays?year=2027')->json('data.holidays.0.starts_on'));

        // Removed whole; every change is in the audit log.
        $this->actingAsApi($owner)->deleteJson("/api/v1/admin/holidays/{$saved['id']}")->assertOk();
        $this->assertSame(0, Holiday::query()->where('group_id', $saved['id'])->count());
        $this->actingAsApi($owner)->deleteJson("/api/v1/admin/holidays/{$saved['id']}")->assertNotFound();
        $this->assertSame(4, AuditLog::query()->where('action', 'attendance.holiday_saved')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance.holiday_removed')->count());

        // Granted in Roles, another role can keep it too.
        Role::findByName('admin', 'staff')->givePermissionTo('holidays.manage');
        $this->actingAsApi($admin->fresh())->postJson('/api/v1/admin/holidays', $eid)->assertCreated();
    }
}
