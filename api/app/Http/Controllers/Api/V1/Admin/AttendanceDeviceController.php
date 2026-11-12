<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceSyncEvent;
use App\Models\Staff;
use App\Services\Attendance\AgentPackage;
use App\Services\Attendance\AttendanceRefused;
use App\Services\Attendance\DeviceRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance → device card (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): what the agent last reported, the sync log,
 * the token, the device's network address and commands for the agent, the device's users, and the agent's install
 * package. Reading needs attendance.view_all or attendance.manage; every change, and the package, attendance.manage.
 */
class AttendanceDeviceController extends Controller
{
    /** No check-in for this long and the office PC counts as silent. */
    public const SILENT_MINUTES = 5;

    public function index(AgentPackage $package): JsonResponse
    {
        $devices = AttendanceDevice::query()->orderBy('id')->get();

        return response()->json([
            'data' => $devices->map(fn (AttendanceDevice $device) => self::row($device))->all(),
            // The agent the Attendance card offers for download, or null until the developer has uploaded one.
            'meta' => ['agent_package' => $package->manifest()],
        ]);
    }

    public function store(Request $request, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        [$device, $token] = $registry->create($data['name'], $request->user('staff'));

        return response()->json(['data' => self::row($device), 'token' => $token], 201);
    }

    /** A new token; the old one stops working at once. Shown once. */
    public function rotateToken(Request $request, int $id, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $device = AttendanceDevice::query()->findOrFail($id);
        $token = $registry->rotateToken($device, $request->user('staff'));

        return response()->json(['data' => self::row($device->refresh()), 'token' => $token]);
    }

    public function revoke(Request $request, int $id, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $device = AttendanceDevice::query()->findOrFail($id);
        $registry->revoke($device, $request->user('staff'));

        return response()->json(['data' => self::row($device->refresh())]);
    }

    public function command(Request $request, int $id, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $device = AttendanceDevice::query()->findOrFail($id);
        $data = $request->validate(['command' => ['required', Rule::in(AttendanceDevice::COMMANDS)]]);

        try {
            $registry->requestCommand($device, $data['command'], $request->user('staff'));
        } catch (AttendanceRefused $e) {
            return response()->json(['message' => __("attendance.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => self::row($device->refresh())]);
    }

    public function allowReplacement(Request $request, int $id, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $device = AttendanceDevice::query()->findOrFail($id);
        $registry->allowReplacement($device, $request->user('staff'));

        return response()->json(['data' => self::row($device->refresh())]);
    }

    /** The device's address on the office LAN, for the agent's next check-in; null clears it. Port 4370 unless given. */
    public function address(Request $request, int $id, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $device = AttendanceDevice::query()->findOrFail($id);
        $data = $request->validate([
            'address' => ['present', 'nullable', 'bail', 'string', function (string $attribute, mixed $value, Closure $fail) {
                // Dotted IPv4 (no leading zeros, no shorthand) inside 10/8, 172.16/12 or 192.168/16: the device is on the LAN.
                if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! IpUtils::checkIp($value, AttendanceDevice::PRIVATE_RANGES)) {
                    $fail(__('attendance.device_address_private'));
                }
            }],
            // ZKTeco's own range: any port would let an admin session use the office PC to try services on the LAN.
            'port' => ['nullable', 'integer', 'between:'.AttendanceDevice::PORT_MIN.','.AttendanceDevice::PORT_MAX],
        ], [
            'port.between' => __('attendance.device_port_range', ['min' => AttendanceDevice::PORT_MIN, 'max' => AttendanceDevice::PORT_MAX]),
        ]);
        $updated = $registry->setAddress($device, $data['address'], (int) ($data['port'] ?? AttendanceDevice::DEFAULT_PORT), $request->user('staff'));

        return response()->json(['data' => self::row($updated->refresh())]);
    }

    /** The office agent's install zip, for the office PC (AgentPackage). */
    public function agentPackage(Request $request, AgentPackage $package): JsonResponse|StreamedResponse
    {
        $this->ensureManage($request);
        $manifest = $package->manifest();
        if ($manifest === null) {
            return response()->json(['message' => __('attendance.agent_package_missing'), 'code' => 'agent_package_missing'], Response::HTTP_NOT_FOUND);
        }

        return $package->download($manifest);
    }

    /**
     * The device's users with their match, and who they can be matched to — invited staff too: someone without a login
     * yet still punches. `on_device`: the ID was in the user list of the latest report that carried one; an ID only seen
     * in punches (AgentGateway::punches) isn't.
     */
    public function users(int $id): JsonResponse
    {
        $device = AttendanceDevice::query()->findOrFail($id);
        // The report's sync-log row and each ID it listed carry the same time (AgentGateway::report).
        $listedAt = $device->events()->where('kind', AttendanceSyncEvent::REPORT)->whereNotNull('detail->listed')->latest('id')->value('created_at');

        return response()->json(['data' => [
            'users' => $device->users()->with('staff')->orderByRaw('CAST(device_user_id AS UNSIGNED)')->orderBy('device_user_id')->get()
                ->map(fn (AttendanceDeviceUser $user) => [
                    'id' => $user->id,
                    'device_user_id' => $user->device_user_id,
                    'name_on_device' => $user->name_on_device,
                    'staff' => $user->staff ? ['id' => $user->staff->id, 'name' => $user->staff->name, 'employee_code' => $user->staff->employee_code] : null,
                    'ignored' => $user->ignored_at !== null,
                    'on_device' => $listedAt !== null && $user->last_listed_at !== null && $user->last_listed_at->gte($listedAt),
                    'last_listed_at' => $user->last_listed_at?->toIso8601String(),
                ])->all(),
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name', 'employee_code', 'status'])
                ->map(fn (Staff $staff) => ['id' => $staff->id, 'name' => $staff->name, 'employee_code' => $staff->employee_code, 'status' => $staff->status->value])->all(),
        ]]);
    }

    public function mapUser(Request $request, int $userId, DeviceRegistry $registry): JsonResponse
    {
        $this->ensureManage($request);
        $user = AttendanceDeviceUser::query()->with('device')->findOrFail($userId);
        $data = $request->validate([
            'staff_id' => ['nullable', 'integer', Rule::exists('staff', 'id')],
            'ignored' => ['required', 'boolean'],
        ]);
        $staff = isset($data['staff_id']) ? Staff::query()->find($data['staff_id']) : null;
        $registry->mapUser($user, $staff, (bool) $data['ignored'], $request->user('staff'));

        return $this->users($user->attendance_device_id);
    }

    /** @return array<string, mixed> */
    public static function row(AttendanceDevice $device): array
    {
        $users = $device->users()->selectRaw('SUM(staff_id IS NOT NULL) as mapped, SUM(staff_id IS NULL AND ignored_at IS NULL) as unmapped, SUM(ignored_at IS NOT NULL) as ignored')->first();

        return [
            'id' => $device->id,
            'name' => $device->name,
            'state' => self::state($device),
            // Set on the card and handed to the agent at check-in; reported_address is what the agent last used.
            'device_address' => $device->device_address,
            'device_port' => (int) ($device->device_port ?? AttendanceDevice::DEFAULT_PORT),
            'serial_number' => $device->serial_number,
            'model' => $device->model,
            'firmware' => $device->firmware,
            'reported_address' => $device->reported_address,
            'agent_version' => $device->agent_version,
            'first_check_in_at' => $device->first_check_in_at?->toIso8601String(),
            'last_check_in_at' => $device->last_check_in_at?->toIso8601String(),
            'last_report_at' => $device->last_report_at?->toIso8601String(),
            'last_pull_ok_at' => $device->last_pull_ok_at?->toIso8601String(),
            'last_status' => $device->last_status,
            'last_error' => $device->last_error,
            'clock_offset_seconds' => $device->clock_offset_seconds,
            'users_count' => $device->users_count,
            'fingers_count' => $device->fingers_count,
            'records_count' => $device->records_count,
            'pending_command' => $device->pending_command,
            'command_requested_at' => $device->command_requested_at?->toIso8601String(),
            'command_sent_at' => $device->command_sent_at?->toIso8601String(),
            'command_not_picked_up' => $device->pending_command !== null && $device->command_sent_at === null
                && $device->command_requested_at?->lt(now()->subMinutes(2)),
            'replacement_allowed_at' => $device->replacement_allowed_at?->toIso8601String(),
            'token_rotated_at' => $device->token_rotated_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
            'device_users' => ['mapped' => (int) ($users->mapped ?? 0), 'unmapped' => (int) ($users->unmapped ?? 0), 'ignored' => (int) ($users->ignored ?? 0)],
            'events' => $device->events()->latest('id')->limit(15)->get()->map(fn (AttendanceSyncEvent $event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'status' => $event->status,
                'received' => $event->received,
                'stored' => $event->stored,
                'duplicates' => $event->duplicates,
                'rejected' => $event->rejected,
                'detail' => $event->detail,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** revoked · waiting_for_agent · pc_silent · no_address · device_unreachable · ok */
    public static function state(AttendanceDevice $device): string
    {
        return match (true) {
            $device->revoked_at !== null => 'revoked',
            $device->last_check_in_at === null => 'waiting_for_agent',
            $device->last_check_in_at->lt(now()->subMinutes(self::SILENT_MINUTES)) => 'pc_silent',
            // The agent runs but has no address to try: set one on the card.
            $device->last_status === AttendanceDevice::STATUS_NO_ADDRESS => 'no_address',
            $device->last_status !== null && $device->last_status !== AttendanceDevice::STATUS_OK => 'device_unreachable',
            default => 'ok',
        };
    }

    private function ensureManage(Request $request): void
    {
        abort_unless($request->user('staff')->can('attendance.manage'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
    }
}
