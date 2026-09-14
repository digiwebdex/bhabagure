<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\Staff;
use App\Services\Attendance\AttendanceRefused;
use App\Services\Attendance\LeaveDesk;
use App\Services\Attendance\WorkingDays;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave requests for attendance.manage (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): the queue, recording leave for
 * someone, and approving (paid or unpaid), rejecting or revoking it. The HR badge opens ?status=pending and counts it.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...LeaveRequest::STATUSES])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = self::filtered($filters)->with(['staff', 'filedBy', 'decidedBy', 'events.actor'])
            ->when(($filters['status'] ?? LeaveRequest::PENDING) === LeaveRequest::PENDING, fn (Builder $query) => $query->orderBy('starts_on')->orderBy('id'), fn (Builder $query) => $query->orderByDesc('starts_on')->orderByDesc('id'))
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (LeaveRequest $leave) => self::row($leave, withEvents: true))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** @param array<string, mixed> $filters */
    public static function filtered(array $filters): Builder
    {
        $status = $filters['status'] ?? LeaveRequest::PENDING;

        return LeaveRequest::query()->when($status !== 'all', fn (Builder $query) => $query->where('status', $status));
    }

    /** Leave recorded on someone's behalf: a phone call from home sick, a tour assignment. */
    public function store(Request $request, LeaveDesk $desk): JsonResponse
    {
        $data = self::validateRange($request, ['staff_id' => ['required', 'integer', Rule::exists('staff', 'id')]]);

        try {
            $leave = $desk->file(Staff::query()->findOrFail($data['staff_id']), $data['starts_on'], $data['ends_on'], $data['reason'], $request->user('staff'));
        } catch (AttendanceRefused $e) {
            return self::refused($e);
        }

        return response()->json(['data' => self::row($leave->load(['staff', 'filedBy', 'decidedBy', 'events.actor']), withEvents: true)], 201);
    }

    public function approve(Request $request, int $id, LeaveDesk $desk): JsonResponse
    {
        $data = $request->validate(['paid' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->act(fn () => $desk->approve($this->find($id), (bool) $data['paid'], $data['note'] ?? null, $request->user('staff')));
    }

    public function reject(Request $request, int $id, LeaveDesk $desk): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->act(fn () => $desk->reject($this->find($id), $data['note'], $request->user('staff')));
    }

    public function revoke(Request $request, int $id, LeaveDesk $desk): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->act(fn () => $desk->revoke($this->find($id), $data['note'], $request->user('staff')));
    }

    /** @return array<string, mixed> */
    public static function row(LeaveRequest $leave, bool $withEvents = false): array
    {
        $from = $leave->starts_on->toDateString();
        $to = $leave->ends_on->toDateString();

        return [
            'id' => $leave->id,
            'staff' => $leave->relationLoaded('staff') ? ['id' => $leave->staff->id, 'name' => $leave->staff->name, 'employee_code' => $leave->staff->employee_code] : ['id' => $leave->staff_id],
            'starts_on' => $from,
            'ends_on' => $to,
            'calendar_days' => (int) $leave->starts_on->diffInDays($leave->ends_on) + 1,
            'working_days' => WorkingDays::between($from, $to),
            'reason' => $leave->reason,
            'status' => $leave->status,
            'paid' => $leave->paid,
            'filed_by' => $leave->relationLoaded('filedBy') ? $leave->filedBy?->name : null,
            'filed_for_someone' => $leave->filed_by_staff_id !== $leave->staff_id,
            'decided_by' => $leave->relationLoaded('decidedBy') ? $leave->decidedBy?->name : null,
            'decided_at' => $leave->decided_at?->toIso8601String(),
            'decision_note' => $leave->decision_note,
            'created_at' => $leave->created_at?->toIso8601String(),
            ...($withEvents ? ['events' => $leave->events->map(fn (LeaveRequestEvent $event) => [
                'action' => $event->action,
                'by' => $event->actor?->name,
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
            ])->all()] : []),
        ];
    }

    /**
     * @param  array<string, list<mixed>>  $extra
     * @return array<string, mixed>
     */
    public static function validateRange(Request $request, array $extra = []): array
    {
        return $request->validate($extra + [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $start = (string) $request->input('starts_on');
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1
                    && CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($value)) >= LeaveDesk::MAX_DAYS) {
                    $fail(__('validation.max.numeric', ['attribute' => 'days', 'max' => LeaveDesk::MAX_DAYS]));
                }
            }],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
    }

    public static function refused(AttendanceRefused $e): JsonResponse
    {
        $status = in_array($e->reason, ['own_leave', 'not_your_leave'], true) ? Response::HTTP_FORBIDDEN : Response::HTTP_CONFLICT;

        return response()->json(['message' => __("attendance.{$e->reason}"), 'code' => $e->reason], $status);
    }

    /** @param Closure(): LeaveRequest $action */
    private function act(Closure $action): JsonResponse
    {
        try {
            $leave = $action();
        } catch (AttendanceRefused $e) {
            return self::refused($e);
        }

        return response()->json(['data' => self::row($leave->load(['staff', 'filedBy', 'decidedBy', 'events.actor']), withEvents: true)]);
    }

    private function find(int $id): LeaveRequest
    {
        return LeaveRequest::query()->findOrFail($id);
    }
}
