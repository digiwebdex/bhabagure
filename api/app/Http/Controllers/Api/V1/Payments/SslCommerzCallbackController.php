<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SslCommerz\GatewayUnavailable;
use App\Services\Payments\SslCommerz\SslCommerzGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SSLCommerz posts here: the IPN server-to-server, and success / fail / cancel through the customer's browser.
 * The posted fields are only a tran_id and a val_id to look up — PaymentService confirms everything with SSLCommerz.
 * Browser callbacks always end with a 303 to the website's booking page, which reads the outcome from the API.
 */
class SslCommerzCallbackController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function ipn(Request $request, SslCommerzGateway $gateway): JsonResponse
    {
        if (! $gateway->verifiesSignature($request->post())) {
            Log::warning('SSLCommerz IPN with a bad signature', ['tran_id' => $request->input('tran_id')]);

            return response()->json(['status' => 'ignored'], 400);
        }

        $tranId = (string) $request->input('tran_id');
        $valId = (string) $request->input('val_id');
        $status = (string) $request->input('status');

        try {
            if ($valId !== '' && in_array($status, ['VALID', 'VALIDATED'], true)) {
                $this->payments->settle($tranId, $valId, 'ipn');
            } elseif ($attempt = PaymentAttempt::query()->where('tran_id', $tranId)->first()) {
                $this->payments->closeUnpaid($attempt, $status === 'CANCELLED' ? PaymentAttemptStatus::Cancelled : PaymentAttemptStatus::Failed, 'ipn_'.strtolower($status));
            }
        } catch (GatewayUnavailable) {
            // SSLCommerz retries the IPN; reconciliation catches it otherwise.
            return response()->json(['status' => 'retry'], 503);
        }

        return response()->json(['status' => 'received']);
    }

    public function success(Request $request): RedirectResponse
    {
        return $this->finish($request, fn (string $tranId, string $valId) => $valId !== '' ? $this->payments->settle($tranId, $valId, 'redirect') : null);
    }

    public function fail(Request $request): RedirectResponse
    {
        return $this->finish($request, fn (string $tranId) => $this->closeUnpaid($tranId, PaymentAttemptStatus::Failed, 'customer_failed'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        return $this->finish($request, fn (string $tranId) => $this->closeUnpaid($tranId, PaymentAttemptStatus::Cancelled, 'customer_cancelled'));
    }

    private function closeUnpaid(string $tranId, PaymentAttemptStatus $as, string $reason): ?PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->where('tran_id', $tranId)->first();

        return $attempt ? $this->payments->closeUnpaid($attempt, $as, $reason) : null;
    }

    /** @param callable(string, string): ?PaymentAttempt $handle */
    private function finish(Request $request, callable $handle): RedirectResponse
    {
        $tranId = (string) $request->input('tran_id');
        $attempt = PaymentAttempt::query()->with('booking')->where('tran_id', $tranId)->first();

        try {
            $handle($tranId, (string) $request->input('val_id'));
        } catch (GatewayUnavailable $e) {
            // The booking page shows "checking your payment"; IPN or reconciliation settles it.
            Log::warning('SSLCommerz unreachable during a browser callback', ['tran_id' => $tranId, 'error' => $e->getMessage()]);
        }

        $web = rtrim((string) config('bhabaghure.web_url'), '/');
        if (! $attempt) {
            return redirect()->away($web, 303);
        }
        $prefix = $attempt->booking->locale === 'en' ? '/en' : '';
        if ($attempt->return_to === 'portal') {
            return redirect()->away(rtrim((string) config('bhabaghure.portal_url'), '/')."{$prefix}/trips/{$attempt->booking->reference}", 303);
        }

        return redirect()->away("{$web}{$prefix}/booking/{$attempt->booking->reference}", 303);
    }
}
