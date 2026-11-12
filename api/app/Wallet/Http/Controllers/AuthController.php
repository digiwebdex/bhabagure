<?php

namespace App\Wallet\Http\Controllers;

use App\Models\Staff;
use App\Wallet\Services\WalletAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Wallet sign-in with the staff password, and the authenticator code for someone who has two-step sign-in on
 * (docs/two-step-sign-in.md, since 2026-11-13); sign-out; who is signed in.
 */
final class AuthController extends WalletController
{
    public function password(Request $request, WalletAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        // The password opens the session; with two-step sign-in on, the code too (code_required asks the page for it).
        return $this->attempt(fn () => response()->json(['data' => ['signed_in' => true]])
            ->withCookie(self::cookie($auth->password($data['email'], $data['password'], $request, $data['code'] ?? null), (int) config('wallet.session.absolute_minutes'))));
    }

    public function signOut(Request $request, WalletAuth $auth): JsonResponse
    {
        $auth->signOut($request->cookie((string) config('wallet.session.cookie')));

        return response()->json(['data' => ['signed_in' => false]])->withCookie(self::cookie('', -1));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $this->staff($request);

        // The super admin also sees Access, where wallets are given (docs/personal-wallets.md).
        return response()->json(['data' => ['name' => $staff->name, 'email' => $staff->email, 'locale' => $staff->locale, 'super_admin' => $staff->isSuperAdmin()]]);
    }

    /** HttpOnly, SameSite=Strict, sent only to the wallet API's own paths. */
    private static function cookie(string $value, int $minutes): Cookie
    {
        return Cookie::create(
            name: (string) config('wallet.session.cookie'),
            value: $value,
            expire: $minutes < 0 ? 1 : now()->addMinutes($minutes),
            path: '/api/v1/wallet',
            domain: null,
            secure: (bool) config('wallet.session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }
}
