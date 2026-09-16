<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validation and error messages in the caller's language: the X-Locale header, then a `locale` field in the
 * body (the website's forms send one), then Bangla. Accept-Language is deliberately ignored — many visitors'
 * browsers say English while they read the Bangla site, the same reason the website never redirects on it.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Staff software is English only (the client's decision, 2026-09-16), so its screens get English messages
        // whatever the caller asks for. Everything customers touch stays Bangla first.
        $staffArea = $request->is('api/v1/admin/*', 'api/v1/staff/*', 'api/v1/wallet/*');
        $locale = $staffArea ? 'en' : (collect([$request->header('X-Locale'), $request->input('locale')])
            ->first(fn ($candidate) => in_array($candidate, ['bn', 'en'], true)) ?? 'bn');

        app()->setLocale($locale);

        return $next($request);
    }
}
