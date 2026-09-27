<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;

/**
 * The Messenger half of the inbox (docs/admin-inbox.md §2): the Facebook Page's webhook events
 * (https://developers.facebook.com/docs/messenger-platform/webhooks). Customers' messages, the Page's own replies from
 * Meta Business Suite (echoes), deliveries and reads. The request is signed with the App secret.
 */
final class MessengerInbox
{
    /** Messenger attachment types → the inbox's kinds. */
    private const KINDS = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'document'];

    public function __construct(private readonly InboxRecorder $recorder) {}

    /** X-Hub-Signature-256: "sha256=" + HMAC-SHA256 of the raw body with the App secret. */
    public static function authentic(string $rawBody, ?string $signature): bool
    {
        $secret = MessengerSettings::get()['app_secret'];
        if ($secret === null || $signature === null || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($signature, 7));
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'page') {
            return;
        }
        $pageId = MessengerSettings::get()['page_id'];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if ($pageId !== null && (string) ($entry['id'] ?? '') !== $pageId) {
                continue;
            }
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (is_array($event)) {
                    $this->event($event);
                }
            }
        }
    }

    private function event(array $event): void
    {
        if (isset($event['message']) && is_array($event['message'])) {
            $this->message($event);
        } elseif (isset($event['delivery']['mids'])) {
            ConversationMessage::query()->whereIn('external_message_id', (array) $event['delivery']['mids'])->get()->each(fn (ConversationMessage $m) => $m->advanceTo('delivered'));
        } elseif (isset($event['read']['watermark'])) {
            // Everything the Page sent this customer before the watermark has been read.
            $conversation = Conversation::query()->where('channel', Conversation::MESSENGER)->where('external_id', (string) ($event['sender']['id'] ?? ''))->first();
            $conversation?->messages()->where('direction', ConversationMessage::OUT)->where('sent_at', '<=', Carbon::createFromTimestampMs((int) $event['read']['watermark']))
                ->whereIn('status', ['sent', 'delivered'])->get()->each(fn (ConversationMessage $m) => $m->advanceTo('read'));
        }
    }

    private function message(array $event): void
    {
        $message = $event['message'];
        $echo = (bool) ($message['is_echo'] ?? false);
        // An echo is the Page writing: the customer is the recipient.
        $psid = (string) ($echo ? ($event['recipient']['id'] ?? '') : ($event['sender']['id'] ?? ''));
        $mid = $message['mid'] ?? null;
        if ($psid === '' || ! is_string($mid)) {
            return;
        }
        $sentAt = is_numeric($event['timestamp'] ?? null) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : now();
        $name = $echo || Conversation::query()->where('channel', Conversation::MESSENGER)->where('external_id', $psid)->whereNotNull('name')->exists()
            ? null
            : MessengerClient::fromSettings()?->name($psid);

        $attachments = array_values(array_filter((array) ($message['attachments'] ?? []), fn ($a) => is_array($a) && isset(self::KINDS[$a['type'] ?? ''])));
        $text = is_string($message['text'] ?? null) ? $message['text'] : null;
        if ($text === null && $attachments === []) {
            return;
        }

        $base = [
            'direction' => $echo ? ConversationMessage::OUT : ConversationMessage::IN,
            'origin' => $echo ? ConversationMessage::PHONE : ConversationMessage::CUSTOMER,
            'sent_at' => $sentAt,
        ];
        $who = ['external_id' => $psid, 'name' => $name];

        // A message with several photos becomes one inbox message each; the text rides on the first.
        if ($attachments === []) {
            $this->recorder->record(Conversation::MESSENGER, $who, $base + ['body' => $text, 'external_message_id' => $mid]);

            return;
        }
        foreach ($attachments as $index => $attachment) {
            $url = $attachment['payload']['url'] ?? null;
            $this->recorder->record(Conversation::MESSENGER, $who, $base + [
                'body' => $index === 0 ? $text : null,
                'external_message_id' => $index === 0 ? $mid : $mid.'#'.$index,
                'kind' => self::KINDS[$attachment['type']],
                'source' => is_string($url) ? ['provider' => 'messenger', 'url' => $url] : null,
            ]);
        }
    }
}
