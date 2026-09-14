<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Auth\RefreshTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class StaffAuthController extends Controller
{
    use IssuesTokens;

    public function __construct(private readonly AuditLogger $audit) {}

    protected function guardName(): string
    {
        return 'staff';
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = $this->throttleKey($credentials['email'], $request);
        $this->ensureNotRateLimited($key);

        $staff = Staff::query()->where('email', $credentials['email'])->first();

        if ($staff === null || ! Hash::check($credentials['password'], $staff->password)) {
            RateLimiter::hit($key, 60);
            $this->audit->record('auth.staff.login_failed', subject: $staff, changes: ['email' => $credentials['email']]);

            return $this->invalidCredentials();
        }

        if (! $staff->canSignIn()) {
            $this->audit->record('auth.staff.login_blocked', $staff, $staff);

            return response()->json(['message' => __('auth.suspended'), 'code' => 'account_suspended'], Response::HTTP_FORBIDDEN);
        }

        RateLimiter::clear($key);
        $staff->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->record('auth.staff.login', $staff, $staff);

        return $this->tokenResponse($staff, $request, ['staff' => new StaffResource($staff)]);
    }

    public function refresh(Request $request, RefreshTokens $tokens): JsonResponse
    {
        $rotated = $tokens->rotate('staff', $request->cookie(RefreshTokens::cookieName('staff')), $request);
        $staff = $rotated === null ? null : Staff::query()->find($rotated['subject_id']);

        if ($staff === null || ! $staff->canSignIn()) {
            return $this->refreshFailed();
        }

        return $this->tokenResponse($staff, $request, ['staff' => new StaffResource($staff)], refreshToken: $rotated['token']);
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->endSession($request);
    }

    public function me(Request $request): StaffResource
    {
        return new StaffResource($request->user('staff'));
    }

    public function changePassword(Request $request, RefreshTokens $tokens): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10)],
        ]);

        if (! Hash::check($data['current_password'], $staff->password)) {
            return response()->json([
                'message' => __('auth.password'),
                'errors' => ['current_password' => [__('auth.password')]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $staff->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        // Sign out every other session, then continue this one on a fresh token family.
        $tokens->revokeAllFor('staff', $staff->id);
        $this->guard()->invalidate();
        $this->audit->record('auth.staff.password_changed', $staff, $staff);

        return $this->tokenResponse($staff, $request, ['staff' => new StaffResource($staff)]);
    }
}
