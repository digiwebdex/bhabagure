<?php

namespace App\Wallet\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wallet API answers only on its own host (WALLET_HOST, wallet.bhabaghure.com.bd), which nginx puts behind the
 * office IP allow-list and basic auth; on the company API host it doesn't exist. In production an unset host fails
 * closed.
 *
 * The admin shares the site (bhabaghure.com.bd), so a SameSite cookie alone wouldn't keep a script on another of its
 * hosts out. Every wallet request therefore needs the X-Wallet-Request header — which the API's CORS rules don't allow,
 * so no other origin can send it — and a request from another origin is refused outright. Every wallet response is
 * private and never cached.
 */
final class OnlyOnWalletHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = (string) config('wallet.host');
        $allowed = $host === '' ? ! app()->isProduction() : strcasecmp($request->getHost(), $host) === 0;
        abort_unless($allowed, 404);
        $origin = $request->headers->get('Origin');
        if ($origin !== null && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $request->getHost()) !== 0) {
            return response()->json(['message' => __('wallet.foreign_origin'), 'code' => 'foreign_origin'], 403);
        }
        if ($request->header('X-Wallet-Request') !== '1') {
            return response()->json(['message' => __('wallet.header_missing'), 'code' => 'header_missing'], 403);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
