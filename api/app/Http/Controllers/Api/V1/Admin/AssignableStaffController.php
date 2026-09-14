<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/assignable-staff — who a booking, customer or enquiry can be reassigned to (records.assign only). */
class AssignableStaffController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user('staff')->can('records.assign'), 403, __('auth.forbidden'));

        return response()->json(['data' => Staff::query()->where('status', StaffStatus::Active)->orderBy('name')->get()
            ->filter(fn (Staff $staff) => $staff->can('bookings.view_own') || $staff->can('bookings.view_all'))
            ->map(fn (Staff $staff) => ['id' => $staff->id, 'name' => $staff->name, 'role' => $staff->getRoleNames()->first()])
            ->values()]);
    }
}
