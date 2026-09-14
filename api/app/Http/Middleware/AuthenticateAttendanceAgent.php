<?php

namespace App\Http\Middleware;

use App\Models\AttendanceDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The office agent's device token (docs/phase-7-hr-attendance-bonus-wallet.md §5.2). The bearer token is looked up by its
 * SHA-256 hash; a revoked or unknown one gets 401, and an address that keeps presenting bad tokens is slowed down. The
 * device goes on the request for the controller: a token can only ever speak for its own device.
 */
class AuthenticateAttendanceAgent
{
    public const DEVICE = 'attendance_device';

    private const FAILED_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'attendance-agent-failed|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::FAILED_PER_MINUTE)) {
            return response()->json(['message' => __('attendance.too_many_attempts'), 'code' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $token = (string) $request->bearerToken();
        $device = $token === '' ? null : AttendanceDevice::query()->active()->where('token_hash', AttendanceDevice::tokenHash($token))->first();
        if ($device === null) {
            RateLimiter::hit($key, 60);

            return response()->json(['message' => __('attendance.token_refused'), 'code' => 'token_refused'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::DEVICE, $device);

        return $next($request);
    }
}
