<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Services\Hr\HrRefused;
use App\Services\Hr\StaffInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's accept-invite and reset-password pages (docs/phase-7-hr-attendance-bonus-wallet.md §4.1). The token comes
 * in the POST body — the page reads it from the URL fragment — so it never appears in a URL the server logs. An
 * unknown, used, cancelled or expired link gets the same 410.
 */
class StaffInvitationController extends Controller
{
    use IssuesTokens;

    protected function guardName(): string
    {
        return 'staff';
    }

    public function check(Request $request, StaffInvitations $invitations): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $invitation = $invitations->find($data['token']);

        if ($invitation === null || ! $invitation->staff->canSignIn()) {
            return $this->gone();
        }

        return response()->json(['data' => [
            'purpose' => $invitation->purpose,
            'name' => $invitation->staff->name,
            'email' => $invitation->staff->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    /** Sets the password and signs in, on a new session; every older session of the account ends. */
    public function accept(Request $request, StaffInvitations $invitations): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)],
        ]);

        try {
            $staff = $invitations->accept($data['token'], $data['password']);
        } catch (HrRefused) {
            return $this->gone();
        }
        $staff->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return $this->tokenResponse($staff, $request, ['staff' => new StaffResource($staff)]);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => __('hr.link_invalid'), 'code' => 'link_invalid'], Response::HTTP_GONE);
    }
}
