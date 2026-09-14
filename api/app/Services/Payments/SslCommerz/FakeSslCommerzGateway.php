<?php

namespace App\Services\Payments\SslCommerz;

use App\Models\PaymentAttempt;

/**
 * Local stand-in for SSLCommerz (SSLCOMMERZ_MODE=fake; refused in production). The "gateway page" is a small page on
 * this API (FakeGatewayController) with Pay / Fail / Cancel buttons that post the same callbacks SSLCommerz posts.
 * Validation answers from the attempt's own amount, so the whole settle path runs without a network.
 */
final class FakeSslCommerzGateway implements SslCommerzGateway
{
    /** The store fee SSLCommerz deducts from the settlement — 2.5% on the public sandbox store (2026-09-13 run). */
    public const STORE_FEE_PERCENT = 2.5;

    /** What a misconfigured store adds on top at the gateway (val_id suffix -surcharge). Must never happen for real. */
    public const SURCHARGE_PERCENT = 2.5;

    public function createSession(array $fields): array
    {
        return [
            'status' => 'SUCCESS',
            'sessionkey' => 'FAKE-'.$fields['tran_id'],
            'GatewayPageURL' => url('/api/v1/payments/fake-gateway/'.rawurlencode((string) $fields['tran_id'])),
        ];
    }

    public function validate(string $valId): array
    {
        if (! preg_match('/^FAKE-(?<tran>[A-Za-z0-9]+)-(?<outcome>paid|surcharge|risky)$/', $valId, $match)) {
            return ['status' => 'INVALID_TRANSACTION'];
        }
        $attempt = PaymentAttempt::query()->where('tran_id', $match['tran'])->first();
        if (! $attempt) {
            return ['status' => 'INVALID_TRANSACTION'];
        }

        // Charges exactly what the store asked for, and settles that minus its fee — as the sandbox did.
        $asked = $attempt->expectedPaisa() / 100;
        $amount = $match['outcome'] === 'surcharge' ? round($asked * (1 + self::SURCHARGE_PERCENT / 100), 2) : $asked;

        return [
            'status' => 'VALID',
            'tran_id' => $attempt->tran_id,
            'val_id' => $valId,
            'amount' => number_format($amount, 2, '.', ''),
            'store_amount' => number_format(round($asked * (1 - self::STORE_FEE_PERCENT / 100), 2), 2, '.', ''),
            'currency' => 'BDT',
            'currency_type' => 'BDT',
            'currency_amount' => number_format($amount, 2, '.', ''),
            'bank_tran_id' => 'FAKEBANK'.$attempt->id,
            'card_type' => strtoupper((string) ($attempt->method_hint ?? 'VISA')).'-Fake',
            'risk_level' => $match['outcome'] === 'risky' ? '1' : '0',
            'value_a' => $attempt->booking?->reference,
        ];
    }

    public function queryByTransactionId(string $tranId): array
    {
        $attempt = PaymentAttempt::query()->where('tran_id', $tranId)->first();
        $elements = $attempt?->val_id ? [['status' => 'VALID', 'val_id' => $attempt->val_id, 'tran_id' => $tranId]] : [];

        return ['APIConnect' => 'DONE', 'no_of_trans_found' => count($elements), 'element' => $elements];
    }

    public function verifiesSignature(array $post): bool
    {
        return true;
    }
}
