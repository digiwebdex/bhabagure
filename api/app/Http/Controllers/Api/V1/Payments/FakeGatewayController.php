<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use Illuminate\Http\Response;

/**
 * The page FakeSslCommerzGateway sends customers to (SSLCOMMERZ_MODE=fake, never in production). Its buttons post
 * exactly what SSLCommerz posts to the success, fail and cancel URLs.
 */
class FakeGatewayController extends Controller
{
    public function show(string $tranId): Response
    {
        abort_unless(config('bhabaghure.sslcommerz.mode') === 'fake' && ! app()->isProduction(), 404);
        $attempt = PaymentAttempt::query()->with('booking')->where('tran_id', $tranId)->firstOrFail();

        $e = fn (string $value) => htmlspecialchars($value, ENT_QUOTES);
        $button = fn (string $outcome, string $label, string $valId = '') => '<form method="post" action="'.$e(url("/api/v1/payments/sslcommerz/{$outcome}")).'">'
            .'<input type="hidden" name="tran_id" value="'.$e($attempt->tran_id).'">'
            .($valId !== '' ? '<input type="hidden" name="val_id" value="'.$e($valId).'">' : '')
            .'<input type="hidden" name="value_a" value="'.$e($attempt->booking->reference).'">'
            .'<button type="submit">'.$e($label).'</button></form>';

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Fake SSLCommerz</title><style>body{font:16px system-ui;margin:0 auto;max-width:28rem;padding:2rem 1rem}'
            .'form{margin:.5rem 0}button{width:100%;padding:.8rem;font:inherit;cursor:pointer}</style></head><body>'
            .'<p><strong>Fake SSLCommerz</strong> — local testing only, no money moves.</p>'
            .'<p>'.$e($attempt->booking->reference).' · BDT '.$e(number_format($attempt->expectedPaisa() / 100, 2)).' · '.$e((string) $attempt->method_hint).'</p>'
            .$button('success', 'Pay', "FAKE-{$attempt->tran_id}-paid")
            .$button('success', 'Pay, but the gateway adds a surcharge (misconfigured store)', "FAKE-{$attempt->tran_id}-surcharge")
            .$button('fail', 'Fail')
            .$button('cancel', 'Cancel')
            .'</body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
