<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Models\StaffInvitation;
use App\Services\AuditLogger;
use App\Services\Auth\RefreshTokens;
use App\Services\Hr\StaffInvitations;
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

    /**
     * "Forgot password?" on the admin sign-in (client, 2026-10-01): a reset link, the same one an admin can send from
     * Staff, to the email on file and by WhatsApp to the staff member's own phone. The answer is the same whether or not
     * the address belongs to anyone, so the form can't be used to find out who works here; one link a minute at most.
     */
    public function forgotPassword(Request $request, StaffInvitations $links): JsonResponse
    {
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:190']])['email'];
        $staff = Staff::query()->where('email', $email)->first();

        $recent = $staff !== null && StaffInvitation::query()->where('staff_id', $staff->id)->where('purpose', StaffInvitation::RESET)
            ->where('created_at', '>', now()->subMinute())->exists();
        if ($staff !== null && $staff->status === StaffStatus::Active && ! $recent) {
            $plain = $links->issue($staff, StaffInvitation::RESET, null);
            $channels = ['email' => $links->email($staff, $plain, StaffInvitation::RESET), 'whatsapp' => $links->whatsApp($staff, $plain, StaffInvitation::RESET)];
            $this->audit->record('auth.staff.password_reset_requested', $staff, $staff, $channels + ['ip' => $request->ip()]);
        }

        return response()->json(['data' => ['status' => 'sent', 'minutes' => StaffInvitations::RESET_MINUTES]], Response::HTTP_ACCEPTED);
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
