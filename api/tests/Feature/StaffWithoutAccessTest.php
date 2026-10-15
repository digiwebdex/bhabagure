<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\Holiday;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Services\Payroll\PayrollDesk;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/staff-without-access.md: a peon or a video editor on the staff list, attendance and salary sheet without ever
 * signing in; software access given later; each person's salary basis; and a day's status set by hand.
 */
class StaffWithoutAccessTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Dhaka'));
        $this->device = new AttendanceDevice(['name' => 'Office']);
        $this->device->forceFill(['token_hash' => str_repeat('d', 64), 'serial_number' => 'K40-0001'])->save();
    }

    #[Test]
    public function staff_added_without_access_need_no_email_or_role_and_never_sign_in_until_access_is_given(): void
    {
        Mail::fake();
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->postJson('/api/v1/admin/staff', ['name' => 'Karim Mia'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'role']);
        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/staff', [
            'name' => 'Karim Mia', 'software_access' => false, 'designation' => 'Office assistant', 'joined_on' => '2026-01-01', 'salary_basis' => 'fixed',
        ])->assertCreated()
            ->assertJsonPath('invitation', null)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.software_access', false)
            ->assertJsonPath('data.role', null)
            ->assertJsonPath('data.profile.salary_basis', 'fixed')
            ->assertJsonPath('data.actions.change_role', false)
            ->assertJsonPath('data.actions.password_reset', false)
            ->assertJsonPath('data.actions.grant_access', true);
        $karim = Staff::query()->findOrFail($created->json('data.id'));
        $this->assertNull($karim->email);
        $this->assertFalse($karim->canSignIn());

        // No role, no reset link, nothing by the forgotten-password form.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$karim->id}/role", ['role' => 'sales_agent'])->assertConflict()->assertJsonPath('code', 'no_software_access');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$karim->id}/password-reset")->assertConflict()->assertJsonPath('code', 'no_software_access');
        $karim->forceFill(['email' => 'karim@example.test', 'password' => Hash::make('a-known-password')])->save();
        $this->postJson('/api/v1/staff/auth/login', ['email' => 'karim@example.test', 'password' => 'a-known-password'])->assertForbidden();
        $this->postJson('/api/v1/staff/auth/forgot-password', ['email' => 'karim@example.test'])->assertAccepted();
        $this->assertDatabaseMissing('staff_invitations', ['staff_id' => $karim->id]);

        // Suspended and back: active again (there's no password to wait for), so still on attendance and payroll.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$karim->id}/suspend", ['reason' => 'Away for a month'])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$karim->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

        // Access later: an email, a role and the usual invitation; still active, so attendance and salary carry on.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$karim->id}/access", ['email' => $admin->email, 'role' => 'sales_agent'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $given = $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$karim->id}/access", ['email' => 'Karim@Example.test', 'role' => 'sales_agent'])->assertOk()
            ->assertJsonPath('data.software_access', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.email', 'karim@example.test')
            ->assertJsonPath('data.role.name', 'sales_agent');
        $this->assertNotEmpty($given->json('invitation.url'));
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$karim->id}/access", ['email' => 'karim@example.test', 'role' => 'sales_agent'])
            ->assertConflict()->assertJsonPath('code', 'already_has_access');
        $this->assertTrue($karim->refresh()->canSignIn());
    }

    #[Test]
    public function a_fixed_salary_ignores_lateness_and_absence_while_unpaid_leave_costs_a_day_on_the_attendance_basis(): void
    {
        $totals = ['absent_days' => 2, 'leave_unpaid' => 1, 'not_employed_working_days' => 0, 'reduced_days' => 4];
        // From attendance: 26 − (2 absent + 1 unpaid leave + 50 % × 4 reduced) = 21 paid days of 26.
        $this->assertSame(21000, PayrollDesk::figures(26000, 26, 50, $totals, 0)['payable']);
        // Fixed: the month's amount, whatever the attendance — only days outside employment still come off.
        $this->assertSame(26000, PayrollDesk::figures(26000, 26, 50, $totals, 0, 'fixed')['payable']);
        $this->assertSame(23000, PayrollDesk::figures(26000, 26, 50, ['not_employed_working_days' => 3] + $totals, 0, 'fixed')['payable']);
    }

    #[Test]
    public function staff_without_access_are_on_the_salary_sheet_by_their_basis(): void
    {
        $admin = $this->staff('admin');
        $karim = $this->actingAsApi($admin)->postJson('/api/v1/admin/staff', [
            'name' => 'Karim Mia', 'software_access' => false, 'joined_on' => '2026-01-01', 'salary_basis' => 'fixed',
        ])->assertCreated()->json('data.id');
        StaffSalary::query()->create(['staff_id' => $karim, 'effective_month' => '2026-01-01', 'amount' => 12000, 'reason' => 'Joining salary', 'created_by_staff_id' => $admin->id]);

        // No punches at all in September: on the fixed basis, the month's amount all the same.
        $row = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->assertOk()->json('data.rows'))->firstWhere('staff.id', $karim);
        $this->assertSame('fixed', $row['salary_basis']);
        $this->assertSame(12000, $row['figures']['payable']);

        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$karim}", ['salary_basis' => 'attendance'])->assertOk()->assertJsonPath('data.profile.salary_basis', 'attendance');
        $row = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->json('data.rows'))->firstWhere('staff.id', $karim);
        $this->assertSame(0, $row['figures']['payable'], 'absent every working day');
    }

    #[Test]
    public function a_days_status_set_by_hand_overrides_the_device_keeps_the_punches_and_is_undone_by_reversal(): void
    {
        $admin = $this->staff('admin');
        $nabila = $this->person('7');
        $this->punch('7', '2026-09-14', '10:55:00', '19:05:00');

        $this->setDay($admin, $nabila, ['work_date' => '2026-09-14', 'kind' => 'absent', 'reason' => 'Left for personal work, came back to punch out'])->assertCreated();
        $day = $this->days($admin, $nabila)['2026-09-14'];
        $this->assertSame(['absent', ['10:55', '19:05'], true], [$day['status'], $day['punches'], $day['corrected']]);

        // The latest status wins.
        $response = $this->setDay($admin, $nabila, ['work_date' => '2026-09-14', 'kind' => 'leave_unpaid', 'reason' => 'Counted as unpaid leave instead'])->assertCreated();
        $day = $this->days($admin, $nabila)['2026-09-14'];
        $this->assertSame(['leave', ['id' => null, 'paid' => false, 'manual' => true]], [$day['status'], $day['leave']]);

        // Undoing the last one brings back the one before it; undoing both brings back the device.
        $corrections = collect($response->json('data.corrections'))->where('kind', '!=', 'reversal');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/corrections/{$corrections->firstWhere('kind', 'leave_unpaid')['id']}/reverse", ['reason' => 'Wrong'])->assertOk();
        $this->assertSame('absent', $this->days($admin, $nabila)['2026-09-14']['status']);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/attendance/corrections/{$corrections->firstWhere('kind', 'absent')['id']}/reverse", ['reason' => 'Wrong'])->assertOk();
        $this->assertSame('full', $this->days($admin, $nabila)['2026-09-14']['status']);

        // A paid-leave day by hand beats an absence on the device, and the month's totals count it.
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-15', 'kind' => 'leave_paid', 'reason' => 'Sick, phoned in'])->assertCreated();
        $person = $this->actingAsApi($admin)->getJson("/api/v1/admin/attendance/staff/{$nabila->id}?month=2026-09")->json('data');
        $this->assertSame(['leave', true], [collect($person['days'])->firstWhere('date', '2026-09-15')['status'], collect($person['days'])->firstWhere('date', '2026-09-15')['leave']['paid']]);
        $this->assertSame(1, $person['totals']['leave_paid']);

        // A time needs no status, a status needs no time; and a range is for statuses only.
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-16', 'kind' => 'in', 'reason' => 'No time'])->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-16', 'kind' => 'in', 'time' => '10:50', 'until' => '2026-09-17', 'reason' => 'Range'])->assertUnprocessable()->assertJsonValidationErrors('until');
    }

    #[Test]
    public function a_status_over_a_date_range_sets_only_its_working_days(): void
    {
        $admin = $this->staff('admin');
        $nabila = $this->person('7');
        Holiday::query()->create(['date' => '2026-09-21', 'name_en' => 'Office holiday', 'name_bn' => 'অফিস ছুটি']);

        // 19–24 September: Saturday to Thursday — the 21st is a holiday, so five working days.
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-19', 'until' => '2026-09-24', 'kind' => 'leave_unpaid', 'reason' => 'Village visit'])
            ->assertCreated()->assertJsonPath('data.totals.leave_unpaid', 5);
        $days = $this->days($admin, $nabila);
        $this->assertSame('holiday', $days['2026-09-21']['status']);
        $this->assertSame('off', $days['2026-09-18']['status']);
        $this->assertSame('leave', $days['2026-09-24']['status']);

        // Present over a week with a Friday in it: the Friday stays a day off, not a day worked.
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-01', 'until' => '2026-09-05', 'kind' => 'worked', 'reason' => 'Fair in Dhaka'])->assertCreated();
        $days = $this->days($admin, $nabila);
        $this->assertSame(['full', 'off', 'full'], [$days['2026-09-03']['status'], $days['2026-09-04']['status'], $days['2026-09-05']['status']]);

        $this->setDay($admin, $nabila, ['work_date' => '2026-09-01', 'until' => '2026-10-02', 'kind' => 'absent', 'reason' => 'Too long'])->assertConflict()->assertJsonPath('code', 'range_too_long');
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-10', 'until' => '2026-09-09', 'kind' => 'absent', 'reason' => 'Backwards'])->assertConflict()->assertJsonPath('code', 'range_too_long');
        $this->setDay($admin, $nabila, ['work_date' => '2026-10-01', 'until' => '2026-10-08', 'kind' => 'absent', 'reason' => 'Ahead'])->assertConflict()->assertJsonPath('code', 'future_day');
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-25', 'until' => '2026-09-25', 'kind' => 'absent', 'reason' => 'A Friday'])->assertCreated();
        $this->setDay($admin, $nabila, ['work_date' => '2026-09-25', 'until' => '2026-09-26', 'kind' => 'absent', 'reason' => 'Fri and Sat'])->assertCreated();
        $this->assertSame('absent', $this->days($admin, $nabila)['2026-09-26']['status']);
        $this->setDay($admin, $admin, ['work_date' => '2026-09-01', 'until' => '2026-09-03', 'kind' => 'worked', 'reason' => 'Mine'])->assertForbidden()->assertJsonPath('code', 'own_attendance');
    }

    private function person(string $deviceUserId): Staff
    {
        $staff = $this->staff('sales_agent');
        $staff->profile()->create(['joined_on' => '2026-01-01']);
        $user = AttendanceDeviceUser::query()->create(['attendance_device_id' => $this->device->id, 'device_user_id' => $deviceUserId]);
        $user->forceFill(['staff_id' => $staff->id])->save();

        return $staff;
    }

    private function punch(string $deviceUserId, string $date, string ...$times): void
    {
        foreach ($times as $time) {
            AttendancePunch::query()->create(['attendance_device_id' => $this->device->id, 'device_user_id' => $deviceUserId, 'punched_at' => "{$date} {$time}", 'work_date' => $date]);
        }
    }

    /** @param  array<string, string>  $fields */
    private function setDay(Staff $by, Staff $person, array $fields): TestResponse
    {
        return $this->actingAsApi($by)->postJson('/api/v1/admin/attendance/corrections', ['staff_id' => $person->id] + $fields);
    }

    /** @return array<string, array<string, mixed>> */
    private function days(Staff $viewer, Staff $person): array
    {
        return collect($this->actingAsApi($viewer)->getJson("/api/v1/admin/attendance/staff/{$person->id}?month=2026-09")->assertOk()->json('data.days'))->keyBy('date')->all();
    }
}
