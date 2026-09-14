<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/profile/record — the signed-in staff member's own HR record, read-only, for their Profile page
 * (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). It takes no staff id, so it can't show anyone else's.
 */
class MyRecordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $profile = $staff->profile()->first();

        return response()->json(['data' => [
            'employee_code' => $staff->employee_code,
            'designation' => $profile?->designation,
            'joined_on' => $profile?->joined_on?->toDateString(),
            'date_of_birth' => $profile?->date_of_birth?->toDateString(),
            'nid_number' => $profile?->nid_number,
            'address' => $profile?->address,
            'emergency_contact_name' => $profile?->emergency_contact_name,
            'emergency_contact_phone' => $profile?->emergency_contact_phone,
            'payout_method' => $profile?->payout_method,
            'payout_account' => $profile?->payout_account,
        ]]);
    }
}
