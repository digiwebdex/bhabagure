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
        $locale = collect([$request->header('X-Locale'), $request->input('locale')])
            ->first(fn ($candidate) => in_array($candidate, ['bn', 'en'], true)) ?? 'bn';

        app()->setLocale($locale);

        return $next($request);
    }
}
