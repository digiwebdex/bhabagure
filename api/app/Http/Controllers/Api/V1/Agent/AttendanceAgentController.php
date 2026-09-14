<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAttendanceAgent;
use App\Models\AttendanceDevice;
use App\Services\Attendance\AgentGateway;
use App\Services\Attendance\AttendanceRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/attendance-agent/* — the office agent's three calls (docs/phase-7-hr-attendance-bonus-wallet.md §5.2). Replies
 * carry counts and commands only, never staff data: a stolen token can post punches for one device and learn nothing.
 */
class AttendanceAgentController extends Controller
{
    public function __construct(private readonly AgentGateway $gateway) {}

    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:20'],
            'last_pull' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $this->gateway->checkIn($this->device($request), $data, $request->ip())]);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([AttendanceDevice::STATUS_OK, AttendanceDevice::STATUS_UNREACHABLE, AttendanceDevice::STATUS_ERROR])],
            'serial' => ['nullable', 'required_if:status,ok', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:40'],
            'firmware' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:60'],
            'error' => ['nullable', 'string', 'max:1000'],
            'device_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'pc_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'users_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'fingers_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'records_count' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'users' => ['nullable', 'array', 'max:5000'],
            'users.*.id' => ['required', 'string', 'max:20'],
            'users.*.name' => ['nullable', 'string', 'max:100'],
            'command' => ['nullable', 'array'],
            'command.type' => ['required_with:command', Rule::in(AttendanceDevice::COMMANDS)],
            'command.result' => ['required_with:command', Rule::in(['ok', 'failed'])],
            'command.detail' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return response()->json(['data' => $this->gateway->report($this->device($request), $data, $request->ip())]);
        } catch (AttendanceRefused $e) {
            return $this->refused($e);
        }
    }

    public function punches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial' => ['required', 'string', 'max:40'],
            'punches' => ['present', 'array', 'max:'.AgentGateway::BATCH_MAX],
            'punches.*.user_id' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z_-]+$/'],
            'punches.*.time' => ['required', 'date_format:Y-m-d H:i:s'],
            'punches.*.verify' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'punches.*.state' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        try {
            return response()->json(['data' => $this->gateway->punches($this->device($request), $data['serial'], $data['punches'], $request->ip())]);
        } catch (AttendanceRefused $e) {
            return $this->refused($e);
        }
    }

    private function device(Request $request): AttendanceDevice
    {
        return $request->attributes->get(AuthenticateAttendanceAgent::DEVICE);
    }

    private function refused(AttendanceRefused $e): JsonResponse
    {
        return response()->json(['message' => __("attendance.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
    }
}
