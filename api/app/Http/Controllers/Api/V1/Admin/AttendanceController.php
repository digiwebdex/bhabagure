<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Services\Attendance\AttendanceRefused;
use App\Services\Attendance\CorrectionLog;
use App\Services\Attendance\DailyAttendance;
use App\Services\Attendance\RuleBook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR → Attendance & salary (docs/phase-7-hr-attendance-bonus-wallet.md §5.1, §6): the monthly table, one person's days,
 * the rules and holidays, and corrections. Reading needs attendance.view_all or attendance.manage; changing needs
 * attendance.manage.
 */
class AttendanceController extends Controller
{
    public function month(Request $request, DailyAttendance $attendance): JsonResponse
    {
        $month = $this->monthFrom($request);
        $staff = self::employedIn($month)->with(['roles', 'profile'])->orderBy('name')->get();
        $computed = $attendance->month($month, $staff);
        $rules = AttendanceRule::forMonth($month);

        $rows = $staff->map(fn (Staff $person) => [
            'staff' => self::person($person),
            'totals' => $computed[$person->id]['totals'],
            'today' => collect($computed[$person->id]['days'])->firstWhere('date', now('Asia/Dhaka')->toDateString()),
        ])->values();

        $present = $rows->sum(fn (array $row) => $row['totals']['full'] + $row['totals']['late'] + $row['totals']['early'] + $row['totals']['late_early'] + $row['totals']['single_punch']);
        $expected = $rows->sum(fn (array $row) => $row['totals']['working_days'] - $row['totals']['upcoming'] - $row['totals']['leave_paid'] - $row['totals']['leave_unpaid']);

        return response()->json(['data' => [
            'month' => $month,
            'rules' => RuleBook::snapshot($rules),
            'holidays' => self::holidays($month),
            'kpis' => [
                'attendance_percent' => $expected > 0 ? (int) round($present / $expected * 100) : null,
                'full_days' => $rows->sum(fn (array $row) => $row['totals']['full']),
                'reduced_days' => $rows->sum(fn (array $row) => $row['totals']['reduced_days']),
                'absent_days' => $rows->sum(fn (array $row) => $row['totals']['absent_days']),
                'pending_leave' => LeaveRequest::query()->where('status', LeaveRequest::PENDING)->count(),
            ],
            'rows' => $rows->all(),
        ]]);
    }

    /** One person's month: every day with its punches, corrections and leave. */
    public function staff(Request $request, int $id, DailyAttendance $attendance): JsonResponse
    {
        $month = $this->monthFrom($request);
        $person = Staff::query()->with(['roles', 'profile'])->findOrFail($id);

        return response()->json(['data' => self::detail($person, $month, $attendance)]);
    }

    public function rules(Request $request): JsonResponse
    {
        $month = $this->monthFrom($request);

        return response()->json(['data' => [
            'month' => $month,
            'rules' => RuleBook::snapshot(AttendanceRule::forMonth($month)),
            'versions' => AttendanceRule::query()->orderByDesc('effective_month')->get()->map(fn (AttendanceRule $rule) => RuleBook::snapshot($rule))->all(),
        ]]);
    }

    public function saveRules(Request $request, RuleBook $book): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'duty_start' => ['required', 'date_format:H:i'],
            'duty_end' => ['required', 'date_format:H:i', 'after:duty_start'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'late_early_pay_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'working_days_per_month' => ['required', 'integer', 'min:20', 'max:31'],
            'weekly_off_days' => ['present', 'array', 'max:3'],
            'weekly_off_days.*' => ['integer', 'between:1,7', 'distinct'],
            'single_punch_counts_as' => ['required', Rule::in(AttendanceRule::SINGLE_PUNCH)],
        ]);
        $month = $data['month'];
        unset($data['month']);
        $book->save($month, $data, $request->user('staff'));

        return $this->rules($request->merge(['month' => $month]));
    }

    public function holidayList(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now('Asia/Dhaka')->year);

        return response()->json(['data' => Holiday::query()->whereYear('date', $year)->orderBy('date')->get()->map(fn (Holiday $holiday) => self::holiday($holiday))->all()]);
    }

    public function storeHoliday(Request $request, RuleBook $book): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(['data' => self::holiday($book->addHoliday($data['date'], $data['name_en'], $data['name_bn'], $request->user('staff')))], 201);
    }

    public function destroyHoliday(Request $request, int $id, RuleBook $book): JsonResponse
    {
        $this->ensureManage($request);
        $book->removeHoliday(Holiday::query()->findOrFail($id), $request->user('staff'));

        return response()->json(['data' => null]);
    }

    public function correct(Request $request, CorrectionLog $log, DailyAttendance $attendance): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('staff', 'id')],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in(AttendanceCorrection::KINDS)],
            'time' => ['nullable', 'required_unless:kind,worked', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $person = Staff::query()->with(['roles', 'profile'])->findOrFail($data['staff_id']);

        try {
            $log->add($person, $data['work_date'], $data['kind'], isset($data['time']) ? $data['time'].':00' : null, $data['reason'], $request->user('staff'));
        } catch (AttendanceRefused $e) {
            return $this->refused($e);
        }

        return response()->json(['data' => self::detail($person, substr($data['work_date'], 0, 7), $attendance)], 201);
    }

    public function reverseCorrection(Request $request, int $id, CorrectionLog $log, DailyAttendance $attendance): JsonResponse
    {
        $this->ensureManage($request);
        $correction = AttendanceCorrection::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $log->reverse($correction, $data['reason'], $request->user('staff'));
        } catch (AttendanceRefused $e) {
            return $this->refused($e);
        }
        $person = Staff::query()->with(['roles', 'profile'])->findOrFail($correction->staff_id);

        return response()->json(['data' => self::detail($person, $correction->work_date->format('Y-m'), $attendance)]);
    }

    /** Staff who can have attendance in a month: signed up (not just invited) and employed on some day of it. */
    public static function employedIn(string $month): Builder
    {
        $start = "{$month}-01";
        $end = CarbonImmutable::parse($start)->endOfMonth()->toDateString();

        return Staff::query()->where('status', '!=', StaffStatus::Invited->value)
            ->where(fn (Builder $query) => $query->whereDoesntHave('profile')
                ->orWhereHas('profile', fn (Builder $profile) => $profile
                    ->where(fn (Builder $joined) => $joined->whereNull('joined_on')->orWhere('joined_on', '<=', $end))
                    ->where(fn (Builder $left) => $left->whereNull('left_on')->orWhere('left_on', '>=', $start))));
    }

    /** @return array<string, mixed> */
    public static function detail(Staff $person, string $month, DailyAttendance $attendance): array
    {
        $computed = $attendance->month($month, [$person])[$person->id];
        $start = "{$month}-01";
        $end = CarbonImmutable::parse($start)->endOfMonth()->toDateString();

        return [
            'staff' => self::person($person),
            'month' => $month,
            'rules' => RuleBook::snapshot(AttendanceRule::forMonth($month)),
            'days' => $computed['days'],
            'totals' => $computed['totals'],
            'corrections' => AttendanceCorrection::query()->with('createdBy')->where('staff_id', $person->id)->whereBetween('work_date', [$start, $end])->orderBy('id')->get()
                ->map(fn (AttendanceCorrection $correction) => [
                    'id' => $correction->id,
                    'work_date' => $correction->work_date->toDateString(),
                    'kind' => $correction->kind,
                    'time' => $correction->time === null ? null : substr((string) $correction->time, 0, 5),
                    'reason' => $correction->reason,
                    'reverses_id' => $correction->reverses_id,
                    'by' => $correction->createdBy?->name,
                    'created_at' => $correction->created_at?->toIso8601String(),
                ])->all(),
            'leave' => LeaveRequest::query()->where('staff_id', $person->id)->overlapping($start, $end)->orderBy('starts_on')->get()
                ->map(fn (LeaveRequest $request) => LeaveRequestController::row($request))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function person(Staff $person): array
    {
        $role = $person->roles->first();

        return [
            'id' => $person->id,
            'name' => $person->name,
            'employee_code' => $person->employee_code,
            'status' => $person->status->value,
            'role' => $role ? StaffController::roleData($role) : null,
            'designation' => $person->profile?->designation,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function holidays(string $month): array
    {
        return Holiday::query()->whereBetween('date', ["{$month}-01", CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString()])
            ->orderBy('date')->get()->map(fn (Holiday $holiday) => self::holiday($holiday))->all();
    }

    /** @return array<string, mixed> */
    private static function holiday(Holiday $holiday): array
    {
        return ['id' => $holiday->id, 'date' => $holiday->date->toDateString(), 'name_en' => $holiday->name_en, 'name_bn' => $holiday->name_bn];
    }

    private function monthFrom(Request $request): string
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return $data['month'] ?? now('Asia/Dhaka')->format('Y-m');
    }

    private function refused(AttendanceRefused $e): JsonResponse
    {
        $status = in_array($e->reason, ['own_attendance'], true) ? Response::HTTP_FORBIDDEN : Response::HTTP_CONFLICT;

        return response()->json(['message' => __("attendance.{$e->reason}"), 'code' => $e->reason], $status);
    }

    private function ensureManage(Request $request): void
    {
        abort_unless($request->user('staff')->can('attendance.manage'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
    }
}
