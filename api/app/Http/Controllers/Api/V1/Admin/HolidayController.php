<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\Attendance\AttendanceRefused;
use App\Services\Attendance\HolidayCalendar;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR → Holiday calendar (docs/holiday-calendar.md). Every staff member reads the year (profile/holidays: their own leave
 * only); adding, changing, removing and copying holidays needs holidays.manage, which no role has by default — the
 * super admin's alone unless granted in Roles (the owner's choice, 2026-10-12).
 */
class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? now('Asia/Dhaka')->year);

        return response()->json(['data' => HolidayCalendar::view($year, $request->user('staff'))]);
    }

    public function store(Request $request, HolidayCalendar $calendar): JsonResponse
    {
        $data = self::validated($request);

        return $this->attempt(fn () => response()->json(['data' => $calendar->save(null, $data['starts_on'], $data['ends_on'], $data['name_en'], $data['name_bn'], $request->user('staff'))], 201));
    }

    public function update(Request $request, string $group, HolidayCalendar $calendar): JsonResponse
    {
        abort_unless(Holiday::query()->where('group_id', $group)->exists(), 404);
        $data = self::validated($request);

        return $this->attempt(fn () => response()->json(['data' => $calendar->save($group, $data['starts_on'], $data['ends_on'], $data['name_en'], $data['name_bn'], $request->user('staff'))]));
    }

    public function destroy(Request $request, string $group, HolidayCalendar $calendar): JsonResponse
    {
        abort_unless(Holiday::query()->where('group_id', $group)->exists(), 404);
        $calendar->remove($group, $request->user('staff'));

        return response()->json(['data' => null]);
    }

    /** Chosen holidays of one year onto the same days of another: fixed-date ones like 21 February. */
    public function copy(Request $request, HolidayCalendar $calendar): JsonResponse
    {
        $data = $request->validate([
            'from_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'to_year' => ['required', 'integer', 'min:2020', 'max:2100', 'different:from_year'],
            'holidays' => ['required', 'array', 'min:1', 'max:60'],
            'holidays.*' => ['string', 'uuid'],
        ]);

        return response()->json(['data' => $calendar->copy((int) $data['from_year'], (int) $data['to_year'], $data['holidays'], $request->user('staff'))]);
    }

    /** @return array{starts_on: string, ends_on: string, name_en: string, name_bn: string} */
    private static function validated(Request $request): array
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2100-12-31'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:2100-12-31'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['required', 'string', 'max:120'],
        ]);

        return ['starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'name_en' => trim($data['name_en']), 'name_bn' => trim($data['name_bn'])];
    }

    /** @param Closure(): JsonResponse $action */
    private function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (AttendanceRefused $e) {
            return LeaveRequestController::refused($e);
        }
    }
}
