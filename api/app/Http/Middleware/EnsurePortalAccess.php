<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth:customer on the portal routes. When staff turn portal sign-in off for a customer, an access token
 * issued before that stops working at once instead of at its 15-minute expiry (docs/phase-6-customer-portal.md §3.1).
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Customer|null $customer */
        $customer = $request->user('customer');

        if ($customer === null || $customer->portal_disabled_at !== null) {
            return response()->json(['message' => __('auth.session_expired'), 'code' => 'session_expired'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
