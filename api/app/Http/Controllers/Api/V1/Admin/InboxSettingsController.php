<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Inbox\MessengerClient;
use App\Services\Inbox\MessengerSettings;
use App\Services\Inbox\WhatsAppInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inbox → Settings (inbox.manage, docs/admin-inbox.md §4): whether the WhatsApp inbox is on and connected, and the
 * Facebook Page connection. The Page token and App secret are write-only: they are checked with Meta, stored
 * encrypted, and never sent back.
 */
class InboxSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->state()]);
    }

    public function connectMessenger(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'page_token' => ['required', 'string', 'min:20', 'max:1000'],
            'app_secret' => [MessengerSettings::get()['app_secret'] === null ? 'required' : 'nullable', 'string', 'min:16', 'max:200'],
        ]);
        $client = new MessengerClient(trim($data['page_token']));
        $page = $client->page();
        if ($page === null) {
            throw ValidationException::withMessages(['page_token' => [__('inbox.page_token_invalid')]]);
        }
        $client->subscribe($page['id']);
        MessengerSettings::save($page['id'], $page['name'], trim($data['page_token']), isset($data['app_secret']) ? trim($data['app_secret']) : null, $request->user('staff'));
        $audit->record('inbox.messenger_connected', $request->user('staff'), null, ['page_id' => $page['id']]);

        return response()->json(['data' => $this->state()]);
    }

    public function disconnectMessenger(Request $request, AuditLogger $audit): JsonResponse
    {
        MessengerSettings::disconnect($request->user('staff'));
        $audit->record('inbox.messenger_disconnected', $request->user('staff'), null);

        return response()->json(['data' => $this->state()]);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $messenger = MessengerSettings::get();
        $api = rtrim((string) config('app.url'), '/');

        return [
            'whatsapp' => [
                'enabled' => (bool) config('bhabaghure.notifications.inbox.whatsapp'),
                'configured' => WhatsAppInbox::gateway() !== null,
                'webhook_secret_set' => (string) config('bhabaghure.notifications.whatsapp.webhook_secret') !== '',
                'session_status' => WhatsAppInbox::enabled() ? WhatsAppInbox::gateway()?->sessionStatus() : null,
                'webhook_url' => $api.'/api/v1/webhooks/wasender',
                'events' => ['messages.received', 'messages.upsert', 'messages.update', 'message.sent', 'session.status'],
            ],
            'messenger' => [
                'connected' => MessengerSettings::connected(),
                'page_id' => $messenger['page_id'],
                'page_name' => $messenger['page_name'],
                'connected_at' => $messenger['connected_at'],
                'app_secret_set' => $messenger['app_secret'] !== null,
                'verify_token' => $messenger['verify_token'],
                'webhook_url' => $api.'/api/v1/webhooks/messenger',
                'fields' => ['messages', 'message_echoes', 'message_deliveries', 'message_reads'],
            ],
        ];
    }
}
