<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API response, the HTML ones included (invoice and quotation share pages, the invoice print view): never framed
 * — the admin shows its invoice preview with srcdoc, not by framing the API — no MIME sniffing, and no Referer, since
 * share links carry their token in the path. A response that already set one of these keeps its own. HSTS is left to
 * Cloudflare (docs/handover.md §6.1).
 */
final class SecurityHeaders
{
    private const HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
