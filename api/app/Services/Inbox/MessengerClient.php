<?php

namespace App\Services\Inbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Meta's Messenger Platform for the Facebook Page (Graph API, docs/admin-inbox.md §4). The Page token goes in the
 * Authorization header, never in a URL or a log.
 */
final class MessengerClient
{
    public function __construct(private readonly string $pageToken) {}

    public static function fromSettings(): ?self
    {
        $token = MessengerSettings::get()['page_token'];

        return $token === null ? null : new self($token);
    }

    /**
     * The Page a token belongs to, to check it before saving.
     *
     * @return array{id: string, name: string}|null
     */
    public function page(): ?array
    {
        try {
            $response = $this->client()->get('/me', ['fields' => 'id,name']);
        } catch (ConnectionException) {
            return null;
        }
        $id = $response->json('id');

        return $response->successful() && is_string($id) ? ['id' => $id, 'name' => (string) $response->json('name', '')] : null;
    }

    /** Receives the Page's messages, deliveries, reads and echoes at the app's webhook. */
    public function subscribe(string $pageId): bool
    {
        try {
            return $this->client()->post("/{$pageId}/subscribed_apps", ['subscribed_fields' => 'messages,message_echoes,message_deliveries,message_reads'])->json('success') === true;
        } catch (ConnectionException) {
            return false;
        }
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
        return Http::baseUrl(rtrim((string) config('bhabaghure.notifications.inbox.graph_url'), '/'))
            ->withToken($this->pageToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
