<?php

namespace App\Services\Notifications\WhatsApp;

use App\Services\Notifications\SendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WaSenderAPI over Laravel's HTTP client (https://wasenderapi.com/api-docs). Uses only the session API key; the
 * account-wide personal access token is never configured here. The key is never logged.
 *
 * The official Laravel SDK isn't used: it is untested on Laravel 13, its webhook check isn't timing-safe and its
 * retries block the worker with sleep().
 */
final class WaSenderGateway implements WhatsAppGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return 'wasender';
    }

    public function sendText(string $to, string $text): SendResult
    {
        return $this->send(['to' => self::e164($to), 'text' => $text]);
    }

    public function sendDocument(string $to, string $documentUrl, string $fileName, string $caption): SendResult
    {
        return $this->send(['to' => self::e164($to), 'text' => $caption, 'documentUrl' => $documentUrl, 'fileName' => $fileName]);
    }

    public function sessionStatus(): string
    {
        try {
            $response = $this->client()->get('/status');
        } catch (ConnectionException) {
            return 'unknown';
        }

        return $response->successful() ? (string) ($response->json('status') ?? 'unknown') : 'unknown';
    }

    public function messageInfo(string $providerMessageId): ?array
    {
        try {
            $response = $this->client()->get('/messages/'.rawurlencode($providerMessageId).'/info');
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $id = $response->json('data.key.id') ?? $response->json('data.message.key.id');
        $status = $response->json('data.status');

        return [
            'whatsapp_id' => is_string($id) && $id !== '' ? $id : null,
            'status' => is_numeric($status) ? (int) $status : null,
        ];
    }

    /** @param array<string, string> $payload */
    private function send(array $payload): SendResult
    {
        try {
            $response = $this->client()->post('/send-message', $payload);
        } catch (ConnectionException $e) {
            return SendResult::retry('provider_unreachable', 60);
        }

        return $this->interpret($response);
    }

    private function interpret(Response $response): SendResult
    {
        $body = $response->json() ?? [];
        $message = (string) ($body['message'] ?? $body['error'] ?? '');

        if ($response->successful() && ($body['success'] ?? false) === true) {
            $id = $body['data']['msgId'] ?? null;

            return SendResult::sent($id === null ? null : (string) $id);
        }

        // Rate limited: WaSender answers with a "retry_after" in the body (and, by its SDK, HTTP 429).
        if ($response->status() === 429 || isset($body['retry_after'])) {
            return SendResult::retry('rate_limited', max(1, (int) ($body['retry_after'] ?? $response->header('Retry-After') ?: 60)));
        }
        if (stripos($message, 'not connected') !== false) {
            return SendResult::retry('session_disconnected', 300);
        }
        if ($response->serverError()) {
            return SendResult::retry('provider_error_'.$response->status(), 120);
        }
        if (stripos($message, 'api key') !== false || $response->status() === 401 || $response->status() === 403) {
            Log::error('WaSender rejected the API key; WhatsApp sending is stopped until WASENDER_API_KEY is fixed.');

            return SendResult::retry('invalid_api_key', 900);
        }
        if (stripos($message, 'subscription') !== false || stripos($message, 'trial') !== false) {
            return SendResult::retry('subscription_required', 900);
        }
        // Undocumented by WaSender for the send call; matched on the wording its webhooks and message logs use
        // ("Invalid number JID", "invalid WhatsApp number"), so it becomes one stable reason and a money-critical
        // message goes by SMS at once. Usually this arrives later, in the message.sent webhook (WaSenderWebhook).
        if (self::meansNotOnWhatsApp($message)) {
            return SendResult::failed('not_on_whatsapp');
        }

        return SendResult::failed(mb_substr($message !== '' ? $message : 'rejected_'.$response->status(), 0, 250));
    }

    private function client()
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);
    }

    public static function meansNotOnWhatsApp(string $message): bool
    {
        return preg_match('/not (on|registered (on|with)) whatsapp|invalid (whatsapp )?number|number jid|does not exist on whatsapp/i', $message) === 1;
    }

    /** 8801711223344 → +8801711223344 (WaSender takes E.164). */
    public static function e164(string $stored): string
    {
        return '+'.ltrim($stored, '+');
    }
}
