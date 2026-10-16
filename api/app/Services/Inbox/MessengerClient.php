<?php

namespace App\Services\Inbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta's Messenger Platform for the Facebook Page (Graph API, docs/admin-inbox.md §4). The Page token goes in the
 * Authorization header, never in a log, and in a URL only where debug_token requires it. With the App secret, every call carries its appsecret_proof (an HMAC
 * of the token, never the secret itself), which an app set to "Require app secret" refuses calls without.
 */
final class MessengerClient
{
    /** What Facebook said when it last refused a call: its own words, for staff to act on. Never holds the token. */
    public ?string $lastError = null;

    public function __construct(private readonly string $pageToken, private readonly ?string $appSecret = null) {}

    public static function fromSettings(): ?self
    {
        $settings = MessengerSettings::get();

        return $settings['page_token'] === null ? null : new self($settings['page_token'], $settings['app_secret']);
    }

    /**
     * The Page a token belongs to, to check it before saving. Asked of debug_token, which needs no permission beyond
     * pages_messaging: reading the Page itself (/me) wants pages_read_engagement, which a Messenger-only app doesn't
     * have (2026-10-17, the Bhabagure inbox app). The name is read only if Facebook allows it.
     *
     * @return array{id: string, name: ?string}|null
     */
    public function page(): ?array
    {
        try {
            // input_token has to be a parameter here (Meta's rule); the request is TLS and nothing logs it.
            $response = $this->client()->get('/debug_token', ['input_token' => $this->pageToken]);
        } catch (ConnectionException) {
            $this->lastError = 'Facebook could not be reached';

            return null;
        }
        $id = $response->json('data.profile_id');
        if (! $response->successful() || $response->json('data.is_valid') !== true || ! is_string($id)) {
            $this->lastError = $response->successful() ? 'The token is not a valid Page access token' : $this->reason($response);

            return null;
        }
        if ($response->json('data.type') !== 'PAGE') {
            $this->lastError = 'This is a '.strtolower((string) $response->json('data.type')).' token, not a Page access token: use Generate next to the Page';

            return null;
        }
        if (! in_array('pages_messaging', (array) $response->json('data.scopes'), true)) {
            $this->lastError = 'The token doesn’t include the pages_messaging permission';

            return null;
        }

        try {
            $named = $this->client()->get('/'.rawurlencode($id), ['fields' => 'name']);
            $name = $named->successful() ? $named->json('name') : null;
        } catch (ConnectionException) {
            $name = null;
        }

        return ['id' => $id, 'name' => is_string($name) && $name !== '' ? $name : null];
    }

    /** Receives the Page's messages, deliveries, reads and echoes at the app's webhook. */
    public function subscribe(string $pageId): bool
    {
        try {
            $response = $this->client()->post("/{$pageId}/subscribed_apps", ['subscribed_fields' => 'messages,message_echoes,message_deliveries,message_reads']);
        } catch (ConnectionException) {
            $this->lastError = 'Facebook could not be reached';

            return false;
        }
        if ($response->json('success') === true) {
            return true;
        }
        $this->lastError = $this->reason($response);

        return false;
    }

    /**
     * Facebook's own message for a refused call, e.g. "Invalid OAuth access token" — short, and never the token, which
     * Facebook sometimes repeats ("Malformed access token EAAG…").
     */
    private function reason(Response $response): string
    {
        $message = $response->json('error.message');
        $message = is_string($message) && $message !== '' ? str_replace($this->pageToken, '[token]', $message) : 'HTTP '.$response->status();

        return mb_substr($message, 0, 300);
    }

    /** The customer's name as Messenger shows it; null when Meta won't say. */
    public function name(string $psid): ?string
    {
        try {
            $response = $this->client()->get('/'.rawurlencode($psid), ['fields' => 'first_name,last_name,name']);
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $name = $response->json('name') ?? trim($response->json('first_name', '').' '.$response->json('last_name', ''));

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * A reply inside the 24-hour window: text, or an attachment Meta fetches from `url`.
     *
     * @return array{ok: bool, id: ?string, error: ?string, retry: bool}
     */
    public function send(string $psid, ?string $text, ?array $attachment = null): array
    {
        $message = $attachment === null
            ? ['text' => (string) $text]
            : ['attachment' => ['type' => $attachment['type'], 'payload' => ['url' => $attachment['url'], 'is_reusable' => false]]];
        try {
            $response = $this->client()->post('/me/messages', [
                'recipient' => ['id' => $psid],
                'messaging_type' => 'RESPONSE',
                'message' => $message,
            ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'id' => null, 'error' => 'provider_unreachable', 'retry' => true];
        }
        if ($response->successful() && is_string($response->json('message_id'))) {
            return ['ok' => true, 'id' => $response->json('message_id'), 'error' => null, 'retry' => false];
        }
        $error = (string) ($response->json('error.message') ?? 'rejected_'.$response->status());
        // Meta's code 10 / subcode 2018278: outside the 24-hour window.
        $code = (int) $response->json('error.code');

        return ['ok' => false, 'id' => null, 'error' => mb_substr($code === 10 ? 'outside_24_hour_window' : $error, 0, 250), 'retry' => $response->serverError() || $code === 613 || $code === 4];
    }

    /**
     * Downloads an attachment from Meta's or WaSender's links (both expire); null when it can't. WaSender's own host gets
     * the session key, which never goes anywhere else.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public static function download(string $url, int $maxBytes, ?string $wasenderKey = null): ?array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $request = Http::timeout(30);
        if ($wasenderKey !== null && ($host === 'wasenderapi.com' || str_ends_with($host, '.wasenderapi.com'))) {
            $request = $request->withToken($wasenderKey);
        }
        try {
            $response = $request->get($url);
        } catch (ConnectionException) {
            return null;
        }
        $body = $response->body();
        if (! $response->successful() || $body === '' || strlen($body) > $maxBytes) {
            return null;
        }

        return ['bytes' => $body, 'mime' => strtok((string) $response->header('Content-Type'), ';') ?: 'application/octet-stream'];
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('bhabaghure.notifications.inbox.graph_url'), '/'))
            ->withToken($this->pageToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15);

        return $this->appSecret === null || $this->appSecret === ''
            ? $request
            : $request->withQueryParameters(['appsecret_proof' => hash_hmac('sha256', $this->pageToken, $this->appSecret)]);
    }
}
