<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Notifications\WhatsApp\WaSenderGateway;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The WhatsApp half of the inbox (docs/admin-inbox.md §2): WaSender's webhook events for the main number, filed into
 * conversations. Only one-to-one chats: groups, statuses and channels are left out. Payload shapes follow
 * https://wasenderapi.com/api-docs/webhooks — `data.messages` is one object or a list; the text is `messageBody`; the
 * number is `key.cleanedSenderPn` because `remoteJid` may be WhatsApp's privacy id (…@lid).
 */
final class WhatsAppInbox
{
    /** Media message types → the inbox's attachment kinds. */
    private const MEDIA = ['imageMessage' => 'image', 'videoMessage' => 'video', 'audioMessage' => 'audio', 'documentMessage' => 'document', 'stickerMessage' => 'sticker', 'documentWithCaptionMessage' => 'document'];

    /** WhatsApp status codes in messages.update. */
    private const STATUSES = [0 => 'failed', 2 => 'sent', 3 => 'delivered', 4 => 'read', 5 => 'read'];

    private const STATUS_WORDS = ['error' => 0, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'played' => 5];

    public function __construct(private readonly InboxRecorder $recorder) {}

    public static function enabled(): bool
    {
        return (bool) config('bhabaghure.notifications.inbox.whatsapp') && self::gateway() !== null;
    }

    /** WaSender for the inbox, whatever WASENDER_MODE says about the automated messages. */
    public static function gateway(): ?WaSenderGateway
    {
        $config = config('bhabaghure.notifications.whatsapp');

        return empty($config['api_key']) ? null : new WaSenderGateway((string) $config['base_url'], (string) $config['api_key'], (int) $config['timeout_seconds']);
    }

    /** @param array<string, mixed> $payload a WaSender webhook, already authenticated */
    public function handle(array $payload): void
    {
        if (! (bool) config('bhabaghure.notifications.inbox.whatsapp')) {
            return;
        }
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $timestamp = is_numeric($payload['timestamp'] ?? null) ? (int) $payload['timestamp'] : null;

        match ($event) {
            'messages.received', 'messages-personal.received', 'messages.upsert' => $this->messages($data, $timestamp),
            'messages.update' => $this->statuses($data),
            'message.sent' => $this->sent($data),
            default => null,
        };
    }

    private function messages(array $data, ?int $timestamp): void
    {
        $messages = $data['messages'] ?? $data;
        $messages = is_array($messages) && array_is_list($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            if (is_array($message)) {
                $this->message($message, $timestamp);
            }
        }
    }

    private function message(array $message, ?int $timestamp): void
    {
        $key = (array) ($message['key'] ?? []);
        $jid = (string) ($key['remoteJid'] ?? $message['remoteJid'] ?? '');
        $id = $key['id'] ?? null;
        // Groups, status updates and channels aren't customer chats.
        if ($jid === '' || ! is_string($id) || preg_match('/@(g\.us|broadcast|newsletter)$/', $jid) === 1) {
            return;
        }
        $fromMe = (bool) ($key['fromMe'] ?? false);
        $who = $this->who($key, $jid, $fromMe, $message);
        if ($who === null) {
            return;
        }

        $content = (array) ($message['message'] ?? []);
        $media = $this->media($content);
        $body = $message['messageBody'] ?? $content['conversation'] ?? Arr::get($content, 'extendedTextMessage.text') ?? ($media !== null ? ($media['caption'] ?? null) : null);
        if (($body === null || trim((string) $body) === '') && $media === null) {
            // Reactions, protocol messages, deletions: nothing to show.
            return;
        }

        $sentAt = is_numeric($message['messageTimestamp'] ?? null) ? Carbon::createFromTimestamp((int) $message['messageTimestamp']) : ($timestamp !== null ? Carbon::createFromTimestamp($timestamp > 9_999_999_999 ? intdiv($timestamp, 1000) : $timestamp) : now());

        $this->recorder->record(Conversation::WHATSAPP, $who, [
            'direction' => $fromMe ? ConversationMessage::OUT : ConversationMessage::IN,
            'origin' => $fromMe ? ConversationMessage::PHONE : ConversationMessage::CUSTOMER,
            'body' => $body === null ? null : (string) $body,
            'external_message_id' => $id,
            'sent_at' => $sentAt,
            'kind' => $media['kind'] ?? null,
            'mime' => $media['mime'] ?? null,
            'file_name' => $media['file_name'] ?? null,
            // What WaSender's decrypt-media call needs to hand the file over (FetchInboxMedia).
            'source' => $media === null ? null : ['provider' => 'wasender', 'message' => ['key' => ['id' => $id], 'message' => [$media['type'] => $content[$media['type']]]]],
        ]);
    }

    /**
     * Who the chat is with: the number when WhatsApp shows it, else its privacy id.
     *
     * @return array{external_id: string, name: ?string, phone: ?string, jid: string, lid: ?string}|null
     */
    private function who(array $key, string $jid, bool $fromMe, array $message): ?array
    {
        $phone = null;
        if (! $fromMe && is_string($key['cleanedSenderPn'] ?? null) && preg_match('/^\d{8,15}$/', $key['cleanedSenderPn']) === 1) {
            $phone = $key['cleanedSenderPn'];
        } elseif (! $fromMe && is_string($key['senderPn'] ?? null) && preg_match('/^(\d{8,15})@s\.whatsapp\.net$/', $key['senderPn'], $m) === 1) {
            $phone = $m[1];
        } elseif (preg_match('/^(\d{8,15})@s\.whatsapp\.net$/', $jid, $m) === 1) {
            $phone = $m[1];
        }
        $lid = str_ends_with($jid, '@lid') ? $jid : (is_string($key['senderLid'] ?? null) && str_ends_with($key['senderLid'], '@lid') ? $key['senderLid'] : null);
        if ($phone === null && $lid === null) {
            return null;
        }
        $name = ! $fromMe && is_string($message['pushName'] ?? null) ? $message['pushName'] : null;

        return [
            'external_id' => $phone ?? 'lid:'.strtok($lid, '@'),
            'name' => $name,
            'phone' => $phone,
            // Replies and read receipts go to the number when known.
            'jid' => $phone !== null ? $phone.'@s.whatsapp.net' : $jid,
            'lid' => $lid,
        ];
    }

    /** @return array{type: string, kind: string, mime: ?string, file_name: ?string, caption: ?string}|null */
    private function media(array $content): ?array
    {
        foreach (self::MEDIA as $type => $kind) {
            if (! isset($content[$type]) || ! is_array($content[$type])) {
                continue;
            }
            $media = $content[$type];
            // A document with a caption wraps the document message one level down.
            if ($type === 'documentWithCaptionMessage') {
                $inner = Arr::get($media, 'message.documentMessage');
                if (! is_array($inner)) {
                    continue;
                }
                $type = 'documentMessage';
                $media = $inner;
            }

            return [
                'type' => $type,
                'kind' => $kind,
                'mime' => is_string($media['mimetype'] ?? null) ? strtok($media['mimetype'], ';') : null,
                'file_name' => is_string($media['fileName'] ?? null) ? mb_substr(basename($media['fileName']), 0, 180) : null,
                'caption' => is_string($media['caption'] ?? null) ? $media['caption'] : null,
            ];
        }

        return null;
    }

    /** Delivery ticks on replies sent from here or the phone. */
    private function statuses(array $data): void
    {
        foreach (array_is_list($data) ? $data : [$data] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = Arr::get($entry, 'key.id');
            $raw = Arr::get($entry, 'update.status', -1);
            $code = is_string($raw) && isset(self::STATUS_WORDS[strtolower($raw)]) ? self::STATUS_WORDS[strtolower($raw)] : (is_numeric($raw) ? (int) $raw : -1);
            if (! is_string($id) || ! isset(self::STATUSES[$code])) {
                continue;
            }
            ConversationMessage::query()->where('external_message_id', $id)->where('direction', ConversationMessage::OUT)
                ->get()->each(fn (ConversationMessage $message) => $message->advanceTo(self::STATUSES[$code]));
        }
    }

    /** WaSender's own id for a send, linked to WhatsApp's so the ticks find the reply. */
    private function sent(array $data): void
    {
        $msgId = $data['msgId'] ?? null;
        $whatsAppId = Arr::get($data, 'key.id');
        if ($msgId !== null && is_string($whatsAppId)) {
            ConversationMessage::query()->where('provider_message_id', (string) $msgId)->whereNull('external_message_id')
                ->update(['external_message_id' => $whatsAppId]);
        }
    }
}
