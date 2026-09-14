<?php

use App\Models\Invoice;
use App\Services\Invoices\InvoiceShortLink;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The API host has no pages. Everything lives under /api/v1 (routes/api.php); health check at /up.
Route::get('/', fn () => response()->json(['name' => 'Bhabaghure API', 'version' => 'v1']));

// Short invoice links for SMS (App\Services\Invoices\InvoiceShortLink): a redirect to the public share page.
Route::get('/i/{code}', function (string $code) {
    $invoice = Invoice::query()->where('short_code', $code)->whereIn('status', [Invoice::ISSUED, Invoice::VOID])->first();
    abort_if($invoice === null, 404);

    return redirect()->away(url("/api/v1/public/invoices/{$invoice->share_token}"))
        ->header('Referrer-Policy', 'no-referrer')
        ->header('X-Robots-Tag', 'noindex, nofollow')
        ->header('Cache-Control', 'no-store');
})->where('code', '[A-Za-z0-9]{'.InvoiceShortLink::LENGTH.'}')->middleware('throttle:short-links')->withoutMiddleware([
    // A bare redirect: no session, cookies or CSRF token.
    StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, ValidateCsrfToken::class, AddQueuedCookiesToResponse::class, EncryptCookies::class,
]);
