<?php

namespace App\Services\Payments\SslCommerz;

/**
 * The three SSLCommerz calls this app makes. HttpSslCommerzGateway talks to sandbox or live; FakeSslCommerzGateway
 * stands in locally. Responses are SSLCommerz's own field names, decoded from JSON.
 */
interface SslCommerzGateway
{
    /**
     * Session API (gwprocess/v4/api.php). Returns status, sessionkey, GatewayPageURL, failedreason.
     *
     * @param  array<string, string|int|float>  $fields  without store credentials
     * @return array<string, mixed>
     */
    public function createSession(array $fields): array;

    /**
     * Validation API (validator/api/validationserverAPI.php) for a val_id.
     * Returns status (VALID | VALIDATED | INVALID_TRANSACTION), tran_id, amount, currency, risk_level, bank_tran_id…
     *
     * @return array<string, mixed>
     */
    public function validate(string $valId): array;

    /**
     * Transaction query by tran_id (validator/api/merchantTransIDvalidationAPI.php). Returns `element`: every
     * attempt SSLCommerz knows for that tran_id, each with status and val_id.
     *
     * @return array<string, mixed>
     */
    public function queryByTransactionId(string $tranId): array;

    /** IPN signature check (verify_sign / verify_key). A cheap filter only; settlement still validates. */
    public function verifiesSignature(array $post): bool;
}
