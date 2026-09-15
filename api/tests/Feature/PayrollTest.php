<?php

namespace Tests\Feature;

use App\Mail\PayslipMail;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\PayrollItem;
use App\Models\Staff;
use App\Models\StaffProfile;
use App\Models\StaffSalary;
use App\Models\Transaction;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use App\Services\Payroll\PayrollDesk;
use App\Services\Payroll\PayslipPdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §6: pay = base − day rate × (absent + not employed + (100 % − late pay) ×
 * late-or-early days) + adjustments. The design's sample comes out exactly; a longer month doesn't hand out a free
 * absence; finalising freezes figures and emails payslips; paying records a Salaries cash-out with its receipt.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));
        $this->device = new AttendanceDevice(['name' => 'Office']);
        $this->device->forceFill(['token_hash' => str_repeat('c', 64), 'serial_number' => 'K40-0001'])->save();
        // PDFs are measured elsewhere (InvoicePdfTest); any bytes will do here.
        $this->app->instance(PayslipPdf::class, new class(app(InvoicePdf::class), app(InvoiceView::class)) extends PayslipPdf
        {
            public function pdf(PayrollItem $item, string $locale): string
            {
                return '%PDF-1.4 payslip '.$item->id;
            }
        });
    }

    #[Test]
    public function the_designs_sample_month_comes_out_exactly_and_a_longer_month_gives_no_free_absence(): void
    {
        // Nabila Karim in the design: base ৳ 32,000, 26 working days, 22 full, 2 late, 1 early, 1 leave → ৳ 30,154.
        $this->assertSame(30154, PayrollDesk::figures(32000, 26, 50, ['absent_days' => 0, 'not_employed_working_days' => 0, 'reduced_days' => 3], 0)['payable']);
        // Imran Hossain: ৳ 30,000, 3 late + 2 early → 30000/26 × (26 − 2.5) = ৳ 27,115.
        $this->assertSame(27115, PayrollDesk::figures(30000, 26, 50, ['absent_days' => 0, 'not_employed_working_days' => 0, 'reduced_days' => 5], 0)['payable']);
        // A full month is exactly the base, whatever its length; one absence always costs one day's rate.
        $this->assertSame(32000, PayrollDesk::figures(32000, 26, 50, ['absent_days' => 0, 'not_employed_working_days' => 0, 'reduced_days' => 0], 0)['payable']);
        $this->assertSame(30769, PayrollDesk::figures(32000, 26, 50, ['absent_days' => 1, 'not_employed_working_days' => 0, 'reduced_days' => 0], 0)['payable']);
        // Never below zero, whatever the adjustments.
        $this->assertSame(0, PayrollDesk::figures(16000, 26, 50, ['absent_days' => 26, 'not_employed_working_days' => 0, 'reduced_days' => 0], -500)['payable']);
    }

    #[Test]
    public function a_month_is_figured_live_finalised_with_payslips_and_each_person_paid_by_a_salaries_cash_out(): void
    {
        Mail::fake();
        Storage::fake('local');
        $admin = $this->staff('admin');
        $nabila = $this->person('Nabila Karim', '7', 32000);

        // September 2026: 26 working days. Nabila: every working day full except two late (11:20) and one early (18:10).
        foreach ($this->workingDays('2026-09') as $date) {
            [$in, $out] = match ($date) {
                '2026-09-02', '2026-09-14' => ['11:20:00', '19:05:00'],
                '2026-09-08' => ['10:55:00', '18:10:00'],
                default => ['10:55:00', '19:05:00'],
            };
            $this->punch('7', $date, $in, $out);
        }

        $sheet = $this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->assertOk();
        $row = collect($sheet->json('data.rows'))->firstWhere('staff.id', $nabila->id);
        $this->assertSame(3, $row['totals']['reduced_days']);
        $this->assertSame(30154, $row['figures']['payable']);
        $this->assertSame('draft', $sheet->json('data.status'));

        // An allowance, then finalise: frozen, payslips emailed.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/adjustments', ['staff_id' => $nabila->id, 'amount' => 1500, 'reason' => 'Mustang tour allowance'])->assertOk();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-10/finalise')->assertConflict()->assertJsonPath('code', 'month_not_over');
        $finalised = $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/finalise')->assertOk()->assertJsonPath('data.status', 'finalised');
        $item = PayrollItem::query()->sole();
        $this->assertSame('31654.00', $item->payable);
        Mail::assertSent(PayslipMail::class, fn (PayslipMail $mail) => $mail->hasTo($nabila->email) && $mail->item->is($item));

        // Attendance changes after finalising don't move the frozen figures; salary changes can't reach back.
        $this->punch('7', '2026-09-30', '09:00:00', '20:00:00');
        $this->assertSame(31654, collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->json('data.rows'))->firstWhere('staff.id', $nabila->id)['figures']['payable']);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$nabila->id}/salaries", ['month' => '2026-09', 'amount' => 40000, 'reason' => 'Backdated raise'])->assertConflict()->assertJsonPath('code', 'month_finalised');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/adjustments', ['staff_id' => $nabila->id, 'amount' => 100, 'reason' => 'Late'])->assertConflict();

        // Paying: a Salaries cash-out, from bKash, with its receipt; paid once.
        $this->actingAsApi($admin)->post("/api/v1/admin/payroll-items/{$item->id}/pay", ['method' => 'bkash', 'reference' => 'TRX9K2L1', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertOk();
        $entry = Transaction::query()->where('category', 'salaries')->sole();
        $this->assertSame(['out', '31654.00', 'bkash', 'TRX9K2L1'], [$entry->direction->value, $entry->amount, $entry->method, $entry->reference_label]);
        $this->assertNotNull($entry->evidence_path);
        $this->assertSame($entry->id, $item->fresh()->transaction_id);
        $this->actingAsApi($admin)->post("/api/v1/admin/payroll-items/{$item->id}/pay", ['method' => 'bkash', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonPath('code', 'already_paid');

        // Reopening: the super admin only, and not once anyone is paid.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/reopen', ['reason' => 'Mistake'])->assertForbidden();
        $this->actingAsApi($this->staff('super_admin'))->postJson('/api/v1/admin/payroll/2026-09/reopen', ['reason' => 'Mistake'])->assertConflict()->assertJsonPath('code', 'already_paid');

        // Each person sees their own payslip, and only theirs.
        $this->actingAsApi($nabila)->getJson('/api/v1/admin/profile/payslips')->assertOk()->assertJsonPath('data.0.month', '2026-09');
        $this->actingAsApi($nabila)->get("/api/v1/admin/profile/payslips/{$item->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAsApi($this->staff('sales_agent'))->get("/api/v1/admin/profile/payslips/{$item->id}")->assertNotFound();
        $this->actingAsApi($nabila)->getJson('/api/v1/admin/payroll?month=2026-09')->assertForbidden();
        $this->actingAsApi($this->staff('accountant'))->getJson('/api/v1/admin/payroll?month=2026-09')->assertOk();
        $this->actingAsApi($this->staff('accountant'))->postJson('/api/v1/admin/payroll/2026-09/finalise')->assertForbidden();

        foreach (['payroll.adjustment_added', 'payroll.finalised', 'payroll.paid'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
        $this->assertNotNull($finalised->json('data.finalised_at'));
    }

    #[Test]
    public function people_without_a_base_salary_stay_off_the_payroll_and_a_month_reopened_before_payment_is_a_draft_again(): void
    {
        Mail::fake();
        $admin = $this->staff('admin');
        $owner = $this->staff('super_admin');
        $imran = $this->person('Imran Hossain', '9', null);
        foreach ($this->workingDays('2026-09') as $date) {
            $this->punch('9', $date, '10:50:00', '19:10:00');
        }

        // Nobody here has a salary yet: all three are flagged, and there is nothing to finalise.
        $sheet = $this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->assertOk()->assertJsonPath('data.totals.missing_salary', 3);
        $this->assertNull(collect($sheet->json('data.rows'))->firstWhere('staff.id', $imran->id)['figures']);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/finalise')->assertConflict()->assertJsonPath('code', 'no_salaries');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$imran->id}/salaries", ['month' => '2026-01', 'amount' => 30000, 'reason' => 'Joining salary'])->assertCreated()
            ->assertJsonPath('data.0.amount', 30000);
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/staff/{$imran->id}/salaries", ['month' => '2026-01', 'amount' => 90000, 'reason' => 'No'])->assertForbidden();

        // Finalised with Imran alone: the admin and the owner, still without a salary, get no item.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/finalise')->assertOk();
        $this->assertSame([$imran->id], PayrollItem::query()->pluck('staff_id')->all());
        $this->assertSame('30000.00', PayrollItem::query()->value('payable'));

        $this->actingAsApi($owner)->postJson('/api/v1/admin/payroll/2026-09/reopen', ['reason' => 'Holiday added late'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertSame(0, PayrollItem::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'payroll.reopened')->where('changes->reason', 'Holiday added late')->exists());

        // Someone who left before September isn't on its sheet, so gets no adjustment there.
        $gone = $this->person('Rafiq Islam', '11', 25000, leftOn: '2026-08-20');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/adjustments', ['staff_id' => $gone->id, 'amount' => 2000, 'reason' => 'Final settlement'])
            ->assertConflict()->assertJsonPath('code', 'not_on_sheet');
    }

    #[Test]
    public function someone_joining_mid_month_is_paid_only_for_the_working_days_from_joining(): void
    {
        $admin = $this->staff('admin');
        // Joined Wednesday 16 September: the 13 working days before it (Fridays off) are cut at the day rate.
        $sadia = $this->person('Sadia Rahman', '12', 26000, joinedOn: '2026-09-16');
        foreach ($this->workingDays('2026-09') as $date) {
            if ($date >= '2026-09-16') {
                $this->punch('12', $date, '10:58:00', '19:02:00');
            }
        }

        $row = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->assertOk()->json('data.rows'))->firstWhere('staff.id', $sadia->id);
        $this->assertSame([13, 0, 13], [$row['totals']['not_employed_working_days'], $row['totals']['absent_days'], $row['totals']['full']]);
        // 26,000 − 26,000 ÷ 26 × 13 = ৳ 13,000.
        $this->assertSame(13000, $row['figures']['payable']);
    }

    #[Test]
    public function nobody_handles_their_own_pay_and_a_salary_cash_out_reversed_in_the_cash_book_leaves_the_month_unpaid(): void
    {
        Mail::fake();
        Storage::fake('local');
        $admin = $this->staff('admin');
        $owner = $this->staff('super_admin');
        $nabila = $this->person('Nabila Karim', '7', 32000);
        $this->mapToDevice($admin, '3');
        foreach ($this->workingDays('2026-09') as $date) {
            $this->punch('7', $date, '10:55:00', '19:05:00');
            $this->punch('3', $date, '10:40:00', '19:30:00');
        }

        // The admin's own salary, adjustment and payment are someone else's to record; the super admin's are theirs.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$admin->id}/salaries", ['month' => '2026-01', 'amount' => 60000, 'reason' => 'Raise'])
            ->assertForbidden()->assertJsonPath('code', 'own_pay');
        $this->actingAsApi($owner)->postJson("/api/v1/admin/staff/{$admin->id}/salaries", ['month' => '2026-01', 'amount' => 45000, 'reason' => 'Joining salary'])->assertCreated();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/adjustments', ['staff_id' => $admin->id, 'amount' => 5000, 'reason' => 'Bonus'])
            ->assertForbidden()->assertJsonPath('code', 'own_pay');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/payroll/2026-09/finalise')->assertOk();

        $own = PayrollItem::query()->where('staff_id', $admin->id)->sole();
        $this->actingAsApi($admin)->post("/api/v1/admin/payroll-items/{$own->id}/pay", ['method' => 'cash', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('code', 'own_pay');
        $this->actingAsApi($owner)->post("/api/v1/admin/payroll-items/{$own->id}/pay", ['method' => 'cash', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])->assertOk();

        // Nabila is paid from Nagad, the entry reversed in the cash book: her month is unpaid again, then paid afresh.
        $item = PayrollItem::query()->where('staff_id', $nabila->id)->sole();
        $this->actingAsApi($admin)->post("/api/v1/admin/payroll-items/{$item->id}/pay", ['method' => 'nagad', 'reference' => 'WRONG1', 'occurred_on' => '2026-10-01', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])
            ->assertOk();
        $entry = $item->fresh()->transaction;
        $this->assertSame('2026-10-01 12:00', $entry->occurred_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->actingAsApi($admin)->postJson("/api/v1/admin/cash-book/{$entry->id}/reverse", ['reason' => 'Sent to the wrong number'])->assertCreated();

        $this->assertNull($item->fresh()->paid_at);
        $this->assertNull($item->fresh()->transaction_id);
        $this->assertTrue(AuditLog::query()->where('action', 'payroll.payment_reversed')->where('changes->transaction_id', $entry->id)->exists());
        $row = collect($this->actingAsApi($admin)->getJson('/api/v1/admin/payroll?month=2026-09')->json('data.rows'))->firstWhere('staff.id', $nabila->id);
        $this->assertNull($row['paid_at']);

        $this->actingAsApi($admin)->post("/api/v1/admin/payroll-items/{$item->id}/pay", ['method' => 'bkash', 'reference' => 'TRX7Q', 'evidence' => $this->receipt()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['out', 'in', 'out'], Transaction::query()->where('category', 'salaries')->where('amount', '32000.00')->orderBy('id')->get()->map(fn (Transaction $row) => $row->direction->value)->all());
        $this->assertSame('TRX7Q', $item->fresh()->transaction->reference_label);
    }

    private function person(string $name, string $deviceUserId, ?float $salary, ?string $leftOn = null, string $joinedOn = '2026-01-01'): Staff
    {
        $person = $this->staff('sales_agent', ['name' => $name]);
        StaffProfile::query()->create(['staff_id' => $person->id, 'joined_on' => $joinedOn, 'left_on' => $leftOn]);
        $this->mapToDevice($person, $deviceUserId);
        if ($salary !== null) {
            StaffSalary::query()->create(['staff_id' => $person->id, 'effective_month' => '2026-01-01', 'amount' => $salary, 'reason' => 'Joining salary', 'created_by_staff_id' => $person->id]);
        }

        return $person;
    }

    private function mapToDevice(Staff $person, string $deviceUserId): void
    {
        $user = AttendanceDeviceUser::query()->create(['attendance_device_id' => $this->device->id, 'device_user_id' => $deviceUserId]);
        $user->forceFill(['staff_id' => $person->id])->save();
    }

    /** @return list<string> the month's working days under the default rules (Friday off) */
    private function workingDays(string $month): array
    {
        $days = [];
        for ($day = CarbonImmutable::parse("{$month}-01"); $day->format('Y-m') === $month; $day = $day->addDay()) {
            if ($day->dayOfWeekIso !== 5) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    private function punch(string $deviceUserId, string $date, string ...$times): void
    {
        foreach ($times as $time) {
            AttendancePunch::query()->create(['attendance_device_id' => $this->device->id, 'device_user_id' => $deviceUserId, 'punched_at' => "{$date} {$time}", 'work_date' => $date]);
        }
    }
}
