<?php

namespace App\Services\Notifications\WhatsApp;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Customer;
use App\Models\NotificationMessage;
use App\Services\AuditLogger;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\NotificationPlanner;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * WaSenderAPI webhook events (docs/phase-4-whatsapp.md §6): delivery ticks, the session's connection status, and STOP /
 * START replies. Message text is read only to detect those words and is never stored or logged.
 */
final class WaSenderWebhook
{
    /** WhatsApp status codes in messages.update. */
    private const STATUSES = [0 => NotificationStatus::Failed, 2 => NotificationStatus::Sent, 3 => NotificationStatus::Delivered, 4 => NotificationStatus::Read, 5 => NotificationStatus::Read];

    private const STOP = ['stop', 'unsubscribe', 'বন্ধ'];

    private const START = ['start', 'চালু'];

    public function __construct(
        private readonly NotificationPlanner $planner,
        private readonly AuditLogger $audit,
    ) {}

    /** WaSender puts the webhook secret itself in X-Webhook-Signature (not an HMAC). */
    public static function authentic(?string $signature): bool
    {
        $secret = (string) config('bhabaghure.notifications.whatsapp.webhook_secret');

        return $secret !== '' && $signature !== null && hash_equals($secret, $signature);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        match (true) {
            $event === 'messages.update' => $this->status($data),
            $event === 'message.sent' => $this->sent($data),
            $event === 'session.status' => $this->session($data),
            in_array($event, ['messages.received', 'messages.upsert', 'messages-personal.received'], true) => $this->incoming($data),
            default => null,
        };
    }

    /** WaSender's SDK sends the status as a word inside a list; its docs show one object with a number. Both are read. */
    private const STATUS_WORDS = ['error' => 0, 'pending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'played' => 5];

    /** @param array<string, mixed> $data */
    private function status(array $data): void
    {
        if (array_is_list($data)) {
            foreach ($data as $entry) {
                if (is_array($entry)) {
                    $this->status($entry);
                }
            }

            return;
        }

        $id = Arr::get($data, 'key.id');
        $raw = Arr::get($data, 'update.status', -1);
        $code = is_string($raw) && isset(self::STATUS_WORDS[strtolower($raw)]) ? self::STATUS_WORDS[strtolower($raw)] : (is_numeric($raw) ? (int) $raw : -1);
        $status = self::STATUSES[$code] ?? null;
        if (! is_string($id) || $status === null) {
            return;
        }
        $row = NotificationMessage::query()->where('provider_whatsapp_id', $id)->first();
        if (! $row) {
            return;
        }

        // Only forward: a late "sent" never undoes "read", and an error after delivery is ignored.
        if ($status === NotificationStatus::Failed) {
            if ($row->status->rank() < NotificationStatus::Delivered->rank()) {
                $row->forceFill(['status' => NotificationStatus::Failed, 'failed_at' => now(), 'last_error' => 'whatsapp_error'])->save();
                // WhatsApp gave up on it: a money-critical message goes by SMS.
                $this->planner->smsFallback($row);
            }

            return;
        }
        if (in_array($row->status, [NotificationStatus::Sent, NotificationStatus::Delivered, NotificationStatus::Sending], true) && $status->rank() > $row->status->rank()) {
            $row->forceFill(['status' => $status] + match ($status) {
                NotificationStatus::Delivered => ['delivered_at' => now()],
                NotificationStatus::Read => ['read_at' => now(), 'delivered_at' => $row->delivered_at ?? now()],
                default => [],
            })->save();
        }
    }

    /** @param array<string, mixed> $data */
    private function sent(array $data): void
    {
        // A failed send carries no message id, only text naming the number: "Failed to send message: Invalid number
        // JID: +8801711000001". WaSender may deliver a webhook more than once, so this must be idempotent.
        if (($data['success'] ?? null) === false) {
            $error = (string) ($data['error'] ?? '');
            if (preg_match('/\+?(\d{10,15})\s*$/', $error, $m) !== 1) {
                return;
            }
            $recent = NotificationMessage::query()->where('channel', NotificationChannel::WhatsApp)
                ->whereIn('to_address', [$m[1], '+'.$m[1]])->where('sent_at', '>=', now()->subMinutes(15))->latest('sent_at')->latest('id');

            if (WaSenderGateway::meansNotOnWhatsApp($error)) {
                // The number has no WhatsApp: none of the recent messages to it arrived. Marking them all makes a
                // repeated webhook find nothing left to change.
                $rows = (clone $recent)->where('status', NotificationStatus::Sent)->get();
            } else {
                // Some other failure: only the newest message, and only if it still reads as sent.
                $newest = (clone $recent)->first();
                $rows = $newest && $newest->status === NotificationStatus::Sent ? collect([$newest]) : collect();
            }

            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => NotificationStatus::Failed, 'failed_at' => now(),
                    'last_error' => WaSenderGateway::meansNotOnWhatsApp($error) ? 'not_on_whatsapp' : 'whatsapp_error',
                ])->save();
                $this->planner->smsFallback($row);
            }

            return;
        }

        $msgId = $data['msgId'] ?? null;
        $whatsAppId = Arr::get($data, 'key.id');
        if ($msgId !== null && is_string($whatsAppId)) {
            NotificationMessage::query()->where('provider_message_id', (string) $msgId)->whereNull('provider_whatsapp_id')
                ->update(['provider_whatsapp_id' => $whatsAppId]);
        }
    }

    /** @param array<string, mixed> $data */
    private function session(array $data): void
    {
        $status = strtolower((string) ($data['status'] ?? 'unknown'));
        NotificationSettings::recordSessionStatus($status);
        if ($status !== 'connected') {
            Log::warning('WhatsApp session is not connected', ['status' => $status]);
            AdminAlerts::whatsAppSession($status);
        }
    }

    /** @param array<string, mixed> $data */
    private function incoming(array $data): void
    {
        // WaSender sends either one message object or a list of them under `messages`.
        $messages = isset($data['messages']) ? (array) $data['messages'] : [$data];
        if (! array_is_list($messages)) {
            $messages = [$messages];
        }
        foreach ($messages as $message) {
            if (Arr::get($message, 'key.fromMe')) {
                continue;
            }
            $text = mb_strtolower(trim((string) (Arr::get($message, 'message.conversation') ?? Arr::get($message, 'messageBody') ?? Arr::get($message, 'message.extendedTextMessage.text') ?? '')));
            $word = in_array($text, self::STOP, true) ? 'stop' : (in_array($text, self::START, true) ? 'start' : null);
            $jid = (string) (Arr::get($message, 'key.remoteJid') ?? Arr::get($message, 'remoteJid') ?? '');
            if ($word === null || ! preg_match('/^(\d{10,15})@/', $jid, $m)) {
                continue;
            }

            $phone = $m[1];
            $customers = Customer::query()->where('phone', $phone)->get();
            foreach ($customers as $customer) {
                $optedOut = $word === 'stop';
                if ($optedOut === ($customer->whatsapp_opted_out_at !== null)) {
                    continue;
                }
                $customer->forceFill(['whatsapp_opted_out_at' => $optedOut ? now() : null])->save();
                $this->audit->record($optedOut ? 'customer.whatsapp_opted_out' : 'customer.whatsapp_opted_in', null, $customer, ['via' => 'whatsapp_reply']);
                $this->planner->optOutConfirmation($customer, $phone, $optedOut);
            }
        }
    }
}
