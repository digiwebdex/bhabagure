<?php

namespace App\Services\Inbox;

use App\Jobs\FetchInboxMedia;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\NotificationMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Files a message from WhatsApp or Messenger into its conversation (docs/admin-inbox.md §2), whichever webhook brought
 * it. Webhooks repeat and overlap (WaSender sends a message as both "received" and "upsert"; a reply sent from here
 * comes back as the phone's own message), so recording is idempotent on the provider's message id, and an echo of a
 * staff reply is matched to it instead of being filed twice.
 */
final class InboxRecorder
{
    /**
     * @param  array{external_id: string, name?: ?string, phone?: ?string, jid?: ?string, lid?: ?string}  $who
     * @param  array{direction: string, origin: string, body: ?string, external_message_id: ?string, sent_at: Carbon, kind?: ?string, mime?: ?string, file_name?: ?string, source?: ?array}  $message
     */
    public function record(string $channel, array $who, array $message): ?ConversationMessage
    {
        $recorded = DB::transaction(function () use ($channel, $who, $message) {
            $conversation = $this->conversation($channel, $who);
            $externalId = $message['external_message_id'] ?? null;

            if ($externalId !== null && $conversation->messages()->where('external_message_id', $externalId)->exists()) {
                return null;
            }
            if ($message['direction'] === ConversationMessage::OUT && $this->matchedStaffReply($conversation, $message)) {
                return null;
            }

            $origin = $message['origin'];
            // An automated notification (booking confirmation, receipt) coming back as the phone's own message.
            if ($origin === ConversationMessage::PHONE && $externalId !== null && NotificationMessage::query()->where('provider_whatsapp_id', $externalId)->exists()) {
                $origin = ConversationMessage::AUTOMATED;
            }

            $row = $conversation->messages()->create([
                'direction' => $message['direction'],
                'origin' => $origin,
                'body' => $message['body'] !== null && trim($message['body']) !== '' ? $message['body'] : null,
                'attachment_kind' => $message['kind'] ?? null,
                'attachment_mime' => $message['mime'] ?? null,
                'attachment_name' => $message['file_name'] ?? null,
                'attachment_source' => $message['source'] ?? null,
                'external_message_id' => $externalId,
                'status' => $message['direction'] === ConversationMessage::IN ? 'received' : 'sent',
                'sent_at' => $message['sent_at'],
            ]);
            $this->touch($conversation, $row);

            return $row;
        });

        if ($recorded?->attachment_source !== null) {
            FetchInboxMedia::dispatch($recorded->id);
        }

        return $recorded;
    }

    /** The conversation a message belongs to, found by number or by WhatsApp's privacy id, or started. */
    private function conversation(string $channel, array $who): Conversation
    {
        $conversation = Conversation::query()->where('channel', $channel)->where('external_id', $who['external_id'])->lockForUpdate()->first();
        if (! $conversation && ! empty($who['lid'])) {
            $conversation = Conversation::query()->where('channel', $channel)->where('lid', $who['lid'])->lockForUpdate()->first();
            // The number is known now: the conversation is keyed on it from here on.
            if ($conversation && ! empty($who['phone']) && str_starts_with($conversation->external_id, 'lid:')
                && ! Conversation::query()->where('channel', $channel)->where('external_id', $who['external_id'])->exists()) {
                $conversation->external_id = $who['external_id'];
            }
        }
        $conversation ??= new Conversation(['channel' => $channel, 'external_id' => $who['external_id'], 'status' => Conversation::OPEN, 'unread_count' => 0]);

        foreach (['phone', 'jid', 'lid'] as $field) {
            if (! empty($who[$field])) {
                $conversation->{$field} = $who[$field];
            }
        }
        // WhatsApp's profile name, except over the name staff gave a chat they started (docs/admin-inbox.md §8).
        if (! empty($who['name']) && ($conversation->started_by_staff_id === null || $conversation->name === null)) {
            $conversation->name = mb_substr(trim($who['name']), 0, 120);
        }
        if ($conversation->customer_id === null && $conversation->phone !== null) {
            $conversation->customer_id = Customer::query()->where('phone', $conversation->phone)->value('id');
        }
        $conversation->save();

        return $conversation;
    }

    /** A staff reply coming back from the provider: it gets the provider's id instead of a second copy. */
    private function matchedStaffReply(Conversation $conversation, array $message): bool
    {
        $candidate = $conversation->messages()->where('origin', ConversationMessage::STAFF)->whereNull('external_message_id')
            ->whereIn('status', ['pending', 'sent'])->where('created_at', '>=', now()->subMinutes(10))
            ->when(
                $message['body'] !== null && trim($message['body']) !== '',
                fn ($q) => $q->where('body', trim($message['body'])),
                fn ($q) => $q->where('attachment_kind', $message['kind'] ?? null),
            )
            ->oldest('id')->lockForUpdate()->first();
        if (! $candidate) {
            return false;
        }
        $candidate->forceFill(['external_message_id' => $message['external_message_id'] ?? null])->save();
        $candidate->advanceTo('sent');

        return true;
    }

    /** The list's last message, the unread count and the Messenger reply window; a closed chat opens again on a new message. */
    public function touch(Conversation $conversation, ConversationMessage $message): void
    {
        if ($conversation->last_message_at === null || $message->sent_at->gte($conversation->last_message_at)) {
            $conversation->forceFill([
                'last_message_at' => $message->sent_at,
                'last_message_preview' => $message->preview(),
                'last_message_direction' => $message->direction,
            ]);
        }
        if ($message->direction === ConversationMessage::IN) {
            $conversation->forceFill([
                'unread_count' => $conversation->unread_count + 1,
                'last_incoming_at' => $conversation->last_incoming_at === null || $message->sent_at->gt($conversation->last_incoming_at) ? $message->sent_at : $conversation->last_incoming_at,
                'status' => Conversation::OPEN,
            ]);
        }
        $conversation->save();
    }
}
