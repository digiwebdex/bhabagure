<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Auth\TwoFactor;
use App\Services\Auth\TwoFactorRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own two-step sign-in (docs/two-step-sign-in.md), from their profile: set it up with an authenticator
 * app, confirm it, renew the recovery codes, turn it off. Reachable while the super admin's rule still makes them set it
 * up (outside EnsureStaffCanWork). The secret and the recovery codes are each shown once, here, and never again.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::status($request->user('staff'))]);
    }

    public function setup(Request $request): JsonResponse
    {
        return $this->attempt(fn () => response()->json(['data' => $this->twoFactor->begin($request->user('staff'))]));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return $this->attempt(function () use ($request, $data) {
            $staff = $request->user('staff');
            $codes = $this->twoFactor->confirm($staff, $data['code']);

            return response()->json(['data' => self::status($staff->refresh()) + ['recovery_codes' => $codes]]);
        });
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return $this->attempt(function () use ($request, $data) {
            $staff = $request->user('staff');
            $codes = $this->twoFactor->newRecoveryCodes($staff, $data['code']);

            return response()->json(['data' => self::status($staff->refresh()) + ['recovery_codes' => $codes]]);
        });
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:20']]);

        return $this->attempt(function () use ($request, $data) {
            $staff = $request->user('staff');
            $this->twoFactor->disable($staff, $data['password'], $data['code']);

            return response()->json(['data' => self::status($staff->refresh())]);
        });
    }

    /** @return array{enabled: bool, confirmed_at: ?string, recovery_codes_left: int, required: bool} */
    public static function status(Staff $staff): array
    {
        return [
            'enabled' => $staff->hasTwoFactor(),
            'confirmed_at' => $staff->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_left' => $staff->hasTwoFactor() ? TwoFactor::recoveryCodesLeft($staff) : 0,
            'required' => TwoFactor::required(),
        ];
    }

    private function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (TwoFactorRefused $refused) {
            return response()->json(['message' => $refused->message(), 'code' => "two_factor_{$refused->reason}"], $refused->status());
        }
    }
}
