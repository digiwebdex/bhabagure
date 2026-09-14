<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5.1, §6: days computed from punches in office time under the rules in force
 * — duty 11:00–19:00, no grace, Friday off by default — with corrections, leave, holidays and employment dates, through
 * the device-user mapping only.
 */
class AttendanceDaysTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        // October 2026 in Dhaka: all of September is in the past.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Dhaka'));
        $this->device = new AttendanceDevice(['name' => 'Office']);
        $this->device->forceFill(['token_hash' => str_repeat('a', 64), 'serial_number' => 'K40-0001'])->save();
    }

    #[Test]
    public function every_day_gets_its_status_from_the_punches_and_the_rules(): void
    {
        $admin = $this->staff('admin');
        $nabila = $this->person('7', '2026-09-03');

        $this->punch('7', '2026-09-01', '10:58:12', '19:03:40');   // Tuesday: full
        $this->punch('7', '2026-09-02', '11:00:59', '19:00:00');   // on the minute: still full
        $this->punch('7', '2026-09-03', '11:01:00', '19:10:00');   // late
        $this->punch('7', '2026-09-05', '10:40:00', '18:30:00');   // Saturday: early
        $this->punch('7', '2026-09-06', '11:30:00', '17:00:00');   // late and early: one reduced day
        $this->punch('7', '2026-09-07', '11:00:00');              // a single punch
        $this->punch('7', '2026-09-08', '10:55:00', '11:02:00');   // a second tap on arrival: still a single punch
        $this->punch('7', '2026-09-11', '12:00:00', '15:00:00');   // Friday: worked on a day off
        // 2026-09-09 has no punch: absent. 2026-09-04 and 09-18 are Fridays: off.
        Holiday::query()->create(['date' => '2026-09-10', 'name_en' => 'Office holiday', 'name_bn' => 'অফিস ছুটি']);

        $days = $this->days($admin, $nabila);
        $this->assertSame('not_employed', $days['2026-09-01']['status'], 'before joining on the 3rd');
        $this->assertSame('late', $days['2026-09-03']['status']);
        $this->assertSame(['11:01', '19:10'], [$days['2026-09-03']['in'], $days['2026-09-03']['out']]);
        $this->assertSame('off', $days['2026-09-04']['status']);
        $this->assertSame('early', $days['2026-09-05']['status']);
        $this->assertSame('late_early', $days['2026-09-06']['status']);
        $this->assertSame('single_punch', $days['2026-09-07']['status']);
        $this->assertSame('single_punch', $days['2026-09-08']['status']);
        $this->assertSame('absent', $days['2026-09-09']['status']);
        $this->assertSame('holiday', $days['2026-09-10']['status']);
        $this->assertSame('worked_off', $days['2026-09-11']['status']);

        // The earlier days count once the joining date moves back.
        StaffProfile::query()->where('staff_id', $nabila->id)->update(['joined_on' => '2026-08-01']);
        $days = $this->days($admin, $nabila);
        $this->assertSame('full', $days['2026-09-01']['status']);
        $this->assertSame('full', $days['2026-09-02']['status']);

        // With ten minutes' grace, 11:01 is on time.
        AttendanceRule::query()->update(['grace_minutes' => 10]);
        $this->assertSame('full', $this->days($admin, $nabila)['2026-09-03']['status']);
    }

    #[Test]
    public function totals_count_reduced_and_absent_days_the_way_the_rules_say_a_single_punch_counts(): void
    {
        $admin = $this->staff('admin');
        $imran = $this->person('9', '2026-01-01');
        foreach (['01', '02', '03', '07', '08', '09', '10', '12', '14', '15', '16', '17', '19', '21', '22', '23', '24', '26', '28', '29', '30'] as $day) {
            $this->punch('9', "2026-09-{$day}", '10:50:00', '19:05:00');
        }
        $this->punch('9', '2026-09-05', '10:50:00'); // no punch out

        $month = $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/month?month=2026-09')->assertOk();
        $totals = collect($month->json('data.rows'))->firstWhere('staff.id', $imran->id)['totals'];
        // September 2026 has 26 working days (four Fridays off). Days 06, 13, 20, 27 are Sundays with no punches: absent.
        $this->assertSame(26, $totals['working_days']);
        $this->assertSame(1, $totals['single_punch']);
        $this->assertSame(1, $totals['reduced_days'], 'a single punch counts as a late-or-early day by default');
        $this->assertSame(4, $totals['absent_days']);

        AttendanceRule::query()->update(['single_punch_counts_as' => 'absent']);
        $totals = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/month?month=2026-09')->json('data.rows'))->firstWhere('staff.id', $imran->id)['totals'];
        $this->assertSame(0, $totals['reduced_days']);
        $this->assertSame(5, $totals['absent_days']);
    }

    #[Test]
    public function corrections_set_a_day_by_hand_keep_the_punches_and_are_undone_by_reversal(): void
    {
        $admin = $this->staff('admin');
        $habib = $this->person('12', '2026-01-01');
        $this->punch('12', '2026-09-14', '11:45:00');

        $response = $this->actingAsApi($admin)->postJson('/api/v1/admin/attendance/corrections', [
            'staff_id' => $habib->id, 'work_date' => '2026-09-14', 'kind' => 'in', 'time' => '10:59', 'reason' => 'Punched late after the airport pickup',
        ])->assertCreated();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/attendance/corrections', [
            'staff_id' => $habib->id, 'work_date' => '2026-09-14', 'kind' => 'out', 'time' => '19:15', 'reason' => 'Forgot to punch out',
        ])->assertCreated();
        $day = $this->days($admin, $habib)['2026-09-14'];
        $this->assertSame(['full', '10:59', '19:15', ['11:45'], true], [$day['status'], $day['in'], $day['out'], $day['punches'], $day['corrected']]);

        // Official duty away from the device.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/attendance/corrections', [
            'staff_id' => $habib->id, 'work_date' => '2026-09-15', 'kind' => 'worked', 'reason' => 'Group leader, Mustang tour',
        ])->assertCreated();
        $this->assertSame('full', $this->days($admin, $habib)['2026-09-15']['status']);

        $in = collect($response->json('data.corrections'))->firstWhere('kind', 'in');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/corrections/{$in['id']}/reverse", ['reason' => 'Wrong day'])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/corrections/{$in['id']}/reverse", ['reason' => 'Again'])->assertConflict()->assertJsonPath('code', 'already_reversed');
        $day = $this->days($admin, $habib)['2026-09-14'];
        $this->assertSame(['late', '11:45', '19:15'], [$day['status'], $day['in'], $day['out']]);

        // Nobody corrects their own day, and a day to come can't be corrected.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/attendance/corrections', ['staff_id' => $admin->id, 'work_date' => '2026-09-14', 'kind' => 'worked', 'reason' => 'Mine'])
            ->assertForbidden()->assertJsonPath('code', 'own_attendance');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/attendance/corrections', ['staff_id' => $habib->id, 'work_date' => '2026-10-20', 'kind' => 'worked', 'reason' => 'Later'])
            ->assertConflict()->assertJsonPath('code', 'future_day');
    }

    #[Test]
    public function punches_count_only_through_a_device_user_matched_to_the_person(): void
    {
        $admin = $this->staff('admin');
        $tania = $this->staff('sales_agent');
        // Device user 21 isn't matched to anyone yet.
        $this->punch('21', '2026-09-14', '10:55:00', '19:05:00');
        $this->assertSame('absent', $this->days($admin, $tania)['2026-09-14']['status']);

        $user = AttendanceDeviceUser::query()->create(['attendance_device_id' => $this->device->id, 'device_user_id' => '21', 'name_on_device' => 'Tania']);
        $this->actingAsApi($admin)->putJson("/api/v1/admin/attendance/device-users/{$user->id}", ['staff_id' => $tania->id, 'ignored' => false])->assertOk();
        $this->assertSame('full', $this->days($admin, $tania)['2026-09-14']['status']);

        $this->actingAsApi($admin)->putJson("/api/v1/admin/attendance/device-users/{$user->id}", ['staff_id' => null, 'ignored' => true])->assertOk()
            ->assertJsonPath('data.users.0.ignored', true);
        $this->assertSame('absent', $this->days($admin, $tania)['2026-09-14']['status']);
    }

    #[Test]
    public function reading_needs_attendance_permissions_and_everyone_sees_only_their_own_month(): void
    {
        $agent = $this->person('30', '2026-01-01', 'sales_agent');
        $this->punch('30', '2026-09-14', '10:55:00', '19:05:00');
        $accountant = $this->staff('accountant');

        $this->actingAsApi($agent)->getJson('/api/v1/admin/attendance/month')->assertForbidden();
        $this->actingAsApi($agent)->getJson("/api/v1/admin/attendance/staff/{$accountant->id}")->assertForbidden();
        $mine = $this->actingAsApi($agent)->getJson('/api/v1/admin/profile/attendance?month=2026-09')->assertOk();
        $this->assertSame($agent->id, $mine->json('data.staff.id'));
        $this->assertSame('full', collect($mine->json('data.days'))->firstWhere('date', '2026-09-14')['status']);

        // An accountant reads attendance but changes nothing.
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/attendance/month?month=2026-09')->assertOk();
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/attendance/corrections', ['staff_id' => $agent->id, 'work_date' => '2026-09-14', 'kind' => 'worked', 'reason' => 'No'])->assertForbidden();
        $this->actingAsApi($accountant)->putJson('/api/v1/admin/attendance/rules', ['month' => '2026-10'])->assertForbidden();
    }

    #[Test]
    public function rules_saved_for_a_month_hold_from_then_on_and_leave_earlier_months_alone(): void
    {
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->putJson('/api/v1/admin/attendance/rules', [
            'month' => '2026-10', 'duty_start' => '10:00', 'duty_end' => '18:00', 'grace_minutes' => 15, 'late_early_pay_percent' => 75,
            'working_days_per_month' => 24, 'weekly_off_days' => [5, 6], 'single_punch_counts_as' => 'absent',
        ])->assertOk()->assertJsonPath('data.rules.duty_start', '10:00')->assertJsonPath('data.rules.weekly_off_days', [5, 6]);

        $this->assertSame('11:00', $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/rules?month=2026-09')->json('data.rules.duty_start'));
        $this->assertSame('10:00', $this->actingAsApi($admin)->getJson('/api/v1/admin/attendance/rules?month=2026-12')->json('data.rules.duty_start'));
        $this->actingAsApi($admin)->putJson('/api/v1/admin/attendance/rules', [
            'month' => '2026-11', 'duty_start' => '18:00', 'duty_end' => '10:00', 'grace_minutes' => 0, 'late_early_pay_percent' => 50,
            'working_days_per_month' => 26, 'weekly_off_days' => [5], 'single_punch_counts_as' => 'late_early',
        ])->assertUnprocessable()->assertJsonValidationErrors('duty_end');
    }

    private function person(string $deviceUserId, string $joinedOn, string $role = 'sales_agent'): Staff
    {
        $staff = $this->staff($role);
        StaffProfile::query()->create(['staff_id' => $staff->id, 'joined_on' => $joinedOn]);
        $user = AttendanceDeviceUser::query()->firstOrCreate(['attendance_device_id' => $this->device->id, 'device_user_id' => $deviceUserId]);
        $user->forceFill(['staff_id' => $staff->id])->save();

        return $staff;
    }

    private function punch(string $deviceUserId, string $date, string ...$times): void
    {
        foreach ($times as $time) {
            AttendancePunch::query()->create(['attendance_device_id' => $this->device->id, 'device_user_id' => $deviceUserId, 'punched_at' => "{$date} {$time}", 'work_date' => $date]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function days(Staff $viewer, Staff $person): array
    {
        return collect($this->actingAsApi($viewer)->getJson("/api/v1/admin/attendance/staff/{$person->id}?month=2026-09")->assertOk()->json('data.days'))->keyBy('date')->all();
    }
}
