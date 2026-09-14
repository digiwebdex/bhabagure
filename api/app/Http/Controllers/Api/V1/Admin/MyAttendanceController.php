<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Services\Attendance\AttendanceRefused;
use App\Services\Attendance\DailyAttendance;
use App\Services\Attendance\LeaveDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * profile/attendance and profile/leave-requests — every staff member's own month and leave (docs/phase-7-hr-attendance-
 * bonus-wallet.md §5.1). No permission: the routes take no staff id, so nobody can reach someone else's.
 */
class MyAttendanceController extends Controller
{
    public function month(Request $request, DailyAttendance $attendance): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        /** @var Staff $me */
        $me = $request->user('staff')->load(['roles', 'profile']);
        $detail = AttendanceController::detail($me, $data['month'] ?? now('Asia/Dhaka')->format('Y-m'), $attendance);
        // Corrections name who made them; that stays with HR.
        $detail['corrections'] = array_map(fn (array $correction) => array_diff_key($correction, ['by' => true]), $detail['corrections']);

        return response()->json(['data' => $detail]);
    }

    public function leave(Request $request): JsonResponse
    {
        return response()->json(['data' => LeaveRequest::query()->where('staff_id', $request->user('staff')->id)->with(['decidedBy', 'events.actor'])
            ->orderByDesc('starts_on')->limit(50)->get()->map(fn (LeaveRequest $leave) => LeaveRequestController::row($leave, withEvents: true))->all()]);
    }

    public function file(Request $request, LeaveDesk $desk): JsonResponse
    {
        $data = LeaveRequestController::validateRange($request);
        $me = $request->user('staff');

        try {
            $leave = $desk->file($me, $data['starts_on'], $data['ends_on'], $data['reason'], $me);
        } catch (AttendanceRefused $e) {
            return LeaveRequestController::refused($e);
        }

        return response()->json(['data' => LeaveRequestController::row($leave->load(['events.actor']), withEvents: true)], 201);
    }

    public function cancel(Request $request, int $id, LeaveDesk $desk): JsonResponse
    {
        $leave = LeaveRequest::query()->where('staff_id', $request->user('staff')->id)->findOrFail($id);

        try {
            $cancelled = $desk->cancel($leave, $request->user('staff'));
        } catch (AttendanceRefused $e) {
            return LeaveRequestController::refused($e);
        }

        return response()->json(['data' => LeaveRequestController::row($cancelled->load(['events.actor']), withEvents: true)]);
    }
}
