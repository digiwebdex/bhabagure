<?php

namespace App\Wallet\Http\Middleware;

use App\Wallet\Services\WalletAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A live wallet session from the wallet's own cookie — never the admin's bearer token. */
final class AuthenticateWallet
{
    public const STAFF = 'wallet_staff';

    public function __construct(private readonly WalletAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $staff = $this->auth->resolve($request->cookie((string) config('wallet.session.cookie')));
        if ($staff === null) {
            return response()->json(['message' => __('wallet.signed_out'), 'code' => 'signed_out'], 401);
        }
        $request->attributes->set(self::STAFF, $staff);

        return $next($request);
    }
}
