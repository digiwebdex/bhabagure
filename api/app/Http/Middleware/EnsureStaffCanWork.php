<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:staff. A token issued before a suspension stops working at once, and a staff member
 * who still has to replace their initial password can only reach me / change-password / logout. One who must set up
 * two-step sign-in (the super admin requires it) can only reach that, me and logout.
 */
class EnsureStaffCanWork
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Staff|null $staff */
        $staff = $request->user('staff');

        if ($staff === null || ! $staff->canSignIn()) {
            return response()->json(['message' => __('auth.suspended'), 'code' => 'account_suspended'], Response::HTTP_FORBIDDEN);
        }

        if ($staff->must_change_password) {
            return response()->json(['message' => __('auth.password_change_required'), 'code' => 'password_change_required'], Response::HTTP_FORBIDDEN);
        }

        // The super admin requires two-step sign-in and this person hasn't set it up: only staff/auth/two-factor first
        // (docs/two-step-sign-in.md).
        if (TwoFactor::mustSetUp($staff)) {
            return response()->json(['message' => __('auth.two_factor.setup_required'), 'code' => 'two_factor_setup_required'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
