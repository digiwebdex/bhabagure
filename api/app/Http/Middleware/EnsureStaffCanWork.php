<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:staff. A token issued before a suspension stops working at once, and a staff member
 * who still has to replace their initial password can only reach me / change-password / logout.
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

        return $next($request);
    }
}
