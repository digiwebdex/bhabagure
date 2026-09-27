<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Inbox\InboxFiles;
use App\Services\Inbox\MessengerClient;
use App\Services\Inbox\WhatsAppInbox;
use App\Services\Notifications\NotificationDelivery;
use App\Services\Notifications\SendResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends a staff reply from the inbox (docs/admin-inbox.md). WhatsApp replies share the automated messages' pacing lock,
 * so the number never sends faster than Account Protection allows, whoever is sending; Messenger replies go straight to
 * the Graph API. The reply shows as failed, with the reason, when it can't go.
 */
class SendInboxMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $message = ConversationMessage::query()->with('conversation')->find($this->messageId);
        if (! $message || $message->status !== 'pending') {
            return;
        }

        $message->conversation->channel === Conversation::MESSENGER ? $this->messenger($message) : $this->whatsApp($message);
    }

    private function whatsApp(ConversationMessage $message): void
    {
        $gateway = WhatsAppInbox::gateway();
        if ($gateway === null || ! config('bhabaghure.notifications.inbox.whatsapp')) {
            $this->fail($message, 'whatsapp_not_configured');

            return;
        }
        $lock = Cache::lock(NotificationDelivery::LOCK_KEY, 30);
        if (! $lock->get()) {
            $this->release(3);

            return;
        }
        try {
            $wait = (int) Cache::get(NotificationDelivery::LAST_SEND_KEY, 0) - now()->getTimestamp();
            if ($wait > 0) {
                $this->release($wait);

                return;
            }
            $conversation = $message->conversation;
            $to = $conversation->phone ?? $conversation->jid ?? $conversation->external_id;
            $result = $message->attachment_path !== null
                ? $gateway->sendMedia($to, (string) $message->attachment_kind, InboxFiles::signedUrl($message), $message->attachment_name, (string) $message->body)
                : $gateway->sendTo($to, (string) $message->body);
            if ($result->isSent()) {
                $config = config('bhabaghure.notifications.whatsapp');
                Cache::put(NotificationDelivery::LAST_SEND_KEY, now()->getTimestamp() + (int) $config['seconds_between_sends'] + random_int(0, max(0, (int) $config['jitter_seconds'])), 3600);
            }
        } finally {
            $lock->release();
        }

        $this->outcome($message, $result);
    }

    private function messenger(ConversationMessage $message): void
    {
        $client = MessengerClient::fromSettings();
        if ($client === null) {
            $this->fail($message, 'messenger_not_connected');

            return;
        }
        if (! $message->conversation->canReply()) {
            $this->fail($message, 'outside_24_hour_window');

            return;
        }
        $psid = $message->conversation->external_id;
        // Text and an attachment are separate Messenger messages: the file first, then its caption.
        if ($message->attachment_path !== null) {
            $type = in_array($message->attachment_kind, ['image', 'video', 'audio'], true) ? $message->attachment_kind : 'file';
            $sent = $client->send($psid, null, ['type' => $type, 'url' => InboxFiles::signedUrl($message)]);
            if ($sent['ok'] && trim((string) $message->body) !== '') {
                $client->send($psid, (string) $message->body);
            }
        } else {
            $sent = $client->send($psid, (string) $message->body);
        }

        $this->outcome($message, $sent['ok'] ? SendResult::sent($sent['id']) : ($sent['retry'] ? SendResult::retry((string) $sent['error'], 60) : SendResult::failed((string) $sent['error'])), messenger: true);
    }

    private function outcome(ConversationMessage $message, SendResult $result, bool $messenger = false): void
    {
        if ($result->isSent()) {
            // WhatsApp: WaSender's own id, linked to WhatsApp's by the message.sent webhook. Messenger: the message id
            // itself. A webhook may already have moved it on to delivered: never back.
            $message->refresh();
            $message->forceFill(($messenger
                ? ['external_message_id' => $message->external_message_id ?? $result->messageId]
                : ['provider_message_id' => $result->messageId]) + ['error' => null])->save();
            $message->status === 'pending' ? $message->forceFill(['status' => 'sent'])->save() : null;

            return;
        }
        if ($result->outcome === 'retry' && $this->attempts() < $this->tries) {
            $message->forceFill(['error' => $result->error])->save();
            $this->release(max(5, (int) $result->retryAfterSeconds));

            return;
        }
        $this->fail($message, (string) $result->error);
    }

    private function fail(ConversationMessage $message, string $error): void
    {
        $message->forceFill(['status' => 'failed', 'error' => mb_substr($error, 0, 250)])->save();
    }

    public function failed(Throwable $e): void
    {
        ConversationMessage::query()->whereKey($this->messageId)->where('status', 'pending')->update(['status' => 'failed', 'error' => 'send_failed']);
    }
}
