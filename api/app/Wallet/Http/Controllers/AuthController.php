<?php

namespace App\Wallet\Http\Controllers;

use App\Models\Staff;
use App\Wallet\Services\WalletAuth;
use App\Wallet\Services\WalletRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** Wallet sign-in: password, then the authenticator code (enrolling the app the first time); sign-out; who is signed in. */
final class AuthController extends WalletController
{
    public function password(Request $request, WalletAuth $auth): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => response()->json(['data' => $auth->password($data['email'], $data['password'], $request)]));
    }

    public function code(Request $request, WalletAuth $auth): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'max:10']]);

        try {
            $token = $auth->code($data['challenge'], $data['code'], $request);
        } catch (WalletRefused $e) {
            return response()->json(['message' => __("wallet.{$e->reason}"), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => ['signed_in' => true]])->withCookie(self::cookie($token, (int) config('wallet.session.absolute_minutes')));
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

        return response()->json(['data' => ['name' => $staff->name, 'email' => $staff->email, 'locale' => $staff->locale]]);
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
