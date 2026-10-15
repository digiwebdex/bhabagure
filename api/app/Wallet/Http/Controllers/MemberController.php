<?php

namespace App\Wallet\Http\Controllers;

use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Member;
use App\Wallet\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Access (docs/personal-wallets.md): the super admin gives a personal wallet to the staff they choose, or takes it
 * away. Nobody — the super admin included — sees anyone else's wallet:
 * this screen shows who has one, never what is in it.
 */
final class MemberController extends WalletController
{
    public function index(Request $request): JsonResponse
    {
        $this->superAdminOnly($request);
        $members = Member::query()->get()->keyBy('staff_id');

        // Only staff who can sign in: a wallet is opened with the staff password.
        $staff = Staff::query()->where('status', StaffStatus::Active->value)->where('software_access', true)->orderBy('name')->get()
            ->map(function (Staff $person) use ($members) {
                $member = $members->get($person->id);

                return [
                    'staff_id' => $person->id,
                    'name' => $person->name,
                    'email' => $person->email,
                    'super_admin' => $person->isSuperAdmin(),
                    // The super admin always has one; anyone else while the access stands.
                    'has_wallet' => $person->isSuperAdmin() || ($member !== null && $member->revoked_at === null),
                    'granted_at' => $member?->revoked_at === null ? $member?->granted_at?->toIso8601String() : null,
                ];
            })->values();

        return response()->json(['data' => $staff]);
    }

    public function grant(Request $request): JsonResponse
    {
        $by = $this->superAdminOnly($request);
        $staffId = (int) $request->validate(['staff_id' => ['required', 'integer']])['staff_id'];
        $person = Staff::query()->where('status', StaffStatus::Active->value)->where('software_access', true)->find($staffId);
        if ($person === null) {
            return response()->json(['message' => __('wallet.staff_unknown'), 'code' => 'staff_unknown'], 422);
        }

        if (! $person->isSuperAdmin()) {
            $member = Member::query()->firstOrNew(['staff_id' => $person->id]);
            $member->forceFill(['granted_by_staff_id' => $by->id, 'granted_at' => now(), 'revoked_by_staff_id' => null, 'revoked_at' => null])->save();
            AuditLog::record('wallet_granted', $by->id, $member, ['staff_id' => $person->id]);
        }

        return $this->index($request);
    }

    /** Takes the wallet away: their sessions end now. What they recorded stays in the database, unread by anyone. */
    public function revoke(Request $request, int $staffId): JsonResponse
    {
        $by = $this->superAdminOnly($request);
        $member = Member::query()->where('staff_id', $staffId)->whereNull('revoked_at')->firstOrFail();
        DB::connection('wallet')->transaction(function () use ($member, $by) {
            $member->forceFill(['revoked_by_staff_id' => $by->id, 'revoked_at' => now()])->save();
            Session::query()->where('staff_id', $member->staff_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            AuditLog::record('wallet_revoked', $by->id, $member, ['staff_id' => $member->staff_id]);
        });

        return $this->index($request);
    }

    private function superAdminOnly(Request $request): Staff
    {
        $staff = $this->staff($request);
        abort_unless($staff->isSuperAdmin(), 403, __('wallet.super_admin_only'));

        return $staff;
    }
}
