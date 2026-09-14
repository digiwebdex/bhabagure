<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationPlanner;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff member's own WhatsApp number, for sales alerts and template test sends. It only counts once confirmed with a
 * six-digit code sent to it, so alerts can't be pointed at a number nobody checked.
 */
class ProfileWhatsAppController extends Controller
{
    private const CODE_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function start(Request $request, NotificationPlanner $planner): JsonResponse
    {
        $staff = $request->user('staff');
        $request->merge(['number' => Phone::normalizeBdMobile((string) $request->input('number')) ?? $request->input('number')]);
        $number = $request->validate(['number' => ['required', 'regex:/^8801[3-9]\d{8}$/']])['number'];

        $key = 'whatsapp-verify-start:'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => __('notifications.too_many', ['seconds' => RateLimiter::availableIn($key)]), 'code' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($key, 600);

        $code = (string) random_int(100000, 999999);
        $staff->forceFill([
            'whatsapp_number' => $number, 'whatsapp_verified_at' => null, 'whatsapp_code_hash' => hash('sha256', $code),
            'whatsapp_code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'whatsapp_code_attempts' => 0,
        ])->save();
        $planner->verificationCode($staff, $number, $code);
        $this->audit->record('staff.whatsapp_verification_sent', $staff, $staff);

        return response()->json(['data' => $this->payload($request)], Response::HTTP_ACCEPTED);
    }

    public function verify(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];

        if ($staff->whatsapp_code_hash === null || $staff->whatsapp_code_expires_at?->isPast() || $staff->whatsapp_code_attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => [__('notifications.code_expired')]]);
        }
        if (! hash_equals($staff->whatsapp_code_hash, hash('sha256', $code))) {
            $staff->increment('whatsapp_code_attempts');
            throw ValidationException::withMessages(['code' => [__('notifications.code_wrong')]]);
        }

        $staff->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_code_hash' => null, 'whatsapp_code_expires_at' => null, 'whatsapp_code_attempts' => 0])->save();
        $this->audit->record('staff.whatsapp_verified', $staff, $staff);

        return response()->json(['data' => $this->payload($request)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        $staff->forceFill(['whatsapp_number' => null, 'whatsapp_verified_at' => null, 'whatsapp_code_hash' => null, 'whatsapp_code_expires_at' => null])->save();
        $this->audit->record('staff.whatsapp_removed', $staff, $staff);

        return response()->json(['data' => $this->payload($request)]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $staff = $request->user('staff')->fresh();

        return [
            'number' => $staff->whatsapp_number,
            'verified' => $staff->whatsapp_verified_at !== null,
            'verified_at' => $staff->whatsapp_verified_at?->toIso8601String(),
            'code_pending' => $staff->whatsapp_code_hash !== null && ! $staff->whatsapp_code_expires_at?->isPast(),
        ];
    }
}
