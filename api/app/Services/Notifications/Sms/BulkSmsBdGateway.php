<?php

namespace App\Services\Notifications\Sms;

use App\Services\Notifications\SendResult;
use App\Support\Sms\SmsParts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * bulksmsbd.net (docs/phase-4-whatsapp.md §10, docs/deployment.md §4a). Their docs publish an http:// URL with the key in
 * the query string, and their code samples switch certificate checks off. This client does neither: HTTPS with the
 * certificate verified, POST with the key in the form body, never a GET and never a downgrade to http — their server
 * accepts plain http without redirecting, so only the client can insist on TLS.
 *
 * Every answer is HTTP 200 JSON; the outcome is `response_code` (202 = submitted to the operator, not delivered). On a
 * wrong key the provider echoes the key back in `error_message`, so that text is never stored or logged — only codes.
 */
final class BulkSmsBdGateway implements SmsGateway
{
    /** Configuration or account problems: keep the message, retry later, alert the notification managers. */
    private const ACCOUNT_CODES = [
        1011 => 'sms_auth_failed', 1018 => 'sms_auth_failed',
        1002 => 'sms_sender_id_rejected',
        1006 => 'sms_balance_low', 1007 => 'sms_balance_low',
        1013 => 'sms_account_setup', 1014 => 'sms_account_setup', 1015 => 'sms_account_setup', 1016 => 'sms_account_setup',
        1017 => 'sms_account_setup', 1019 => 'sms_account_setup', 1020 => 'sms_account_setup', 1021 => 'sms_account_setup',
        1032 => 'sms_ip_not_whitelisted',
        // Not in the official table; seen in third-party clients ("Your Account Not Verified").
        1031 => 'sms_auth_failed',
    ];

    /** Won't work for this message however often it's tried. */
    private const PERMANENT_CODES = [
        1001 => 'sms_invalid_number',
        1003 => 'sms_missing_fields',
        1012 => 'sms_masking_needs_bangla',
    ];

    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly int $timeoutSeconds = 15,
        // Their docs only show type=text, and every known client sends it for Bangla too; if Bangla arrives garbled
        // on the first real send, BULKSMSBD_UNICODE_TYPE=unicode switches it without a deploy.
        private readonly string $unicodeType = 'text',
    ) {}

    public function name(): string
    {
        return 'bulksmsbd';
    }

    public function send(string $to, string $text): SendResult
    {
        if (! str_starts_with($this->url, 'https://')) {
            return SendResult::skipped('sms_insecure_url');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout($this->timeoutSeconds)->connectTimeout(5)
                ->post($this->url, [
                    'api_key' => $this->apiKey,
                    'type' => SmsParts::count($text)['encoding'] === 'ucs2' ? $this->unicodeType : 'text',
                    'number' => $to,
                    'senderid' => $this->senderId,
                    'message' => $text,
                ]);
        } catch (ConnectionException $e) {
            return $this->connectionProblem($e);
        }

        $code = $response->json('response_code');
        if (! is_numeric($code)) {
            // An HTML error page, a proxy, or their server having a bad moment. Nothing was accepted.
            return SendResult::retry('sms_provider_error_'.$response->status(), 120);
        }
        $code = (int) $code;

        if ($code === 202) {
            $id = $response->json('message_id');

            return SendResult::sent(is_scalar($id) && $id !== '' ? (string) $id : null);
        }
        if ($code === 1005) {
            return SendResult::retry('sms_provider_error_1005', 120);
        }
        if (isset(self::ACCOUNT_CODES[$code])) {
            Log::error('bulksmsbd.net refused an SMS for an account or configuration reason', ['response_code' => $code]);

            return SendResult::retry(self::ACCOUNT_CODES[$code], 900);
        }

        return SendResult::failed(self::PERMANENT_CODES[$code] ?? "sms_provider_code_{$code}");
    }

    /**
     * A TLS or certificate failure is never answered by trying http. A timeout after the request went out is not
     * retried either: the SMS may already have been accepted and charged, and a second one would reach the customer.
     */
    private function connectionProblem(ConnectionException $e): SendResult
    {
        $message = $e->getMessage();
        if (preg_match('/cURL error (35|51|53|54|58|59|60|64|66|77|80|82|83|90|91)\b|SSL|certificate/i', $message) === 1) {
            Log::error('bulksmsbd.net TLS failure; SMS kept waiting, never sent over http', ['error' => mb_substr($message, 0, 200)]);

            return SendResult::retry('sms_tls_failed', 900);
        }
        if (preg_match('/cURL error 28\b|timed out/i', $message) === 1 && preg_match('/Connection timed out after|Resolving timed out|connect/i', $message) !== 1) {
            return SendResult::failed('sms_timeout_unknown');
        }

        return SendResult::retry('sms_provider_unreachable', 120);
    }
}
