<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Inbox\WhatsAppInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Blue ticks on the customer's phone once staff have opened the chat (docs/admin-inbox.md): the latest message they
 * sent is marked read through WaSender. Best effort — nothing is retried.
 */
class MarkInboxRead implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $conversationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        if (! $conversation || $conversation->channel !== Conversation::WHATSAPP || ! WhatsAppInbox::enabled()) {
            return;
        }
        $latest = $conversation->messages()->where('direction', ConversationMessage::IN)->whereNotNull('external_message_id')->latest('sent_at')->latest('id')->first();
        $jid = $conversation->lid ?? $conversation->jid;
        if ($latest && $jid) {
            WhatsAppInbox::gateway()?->markRead((string) $latest->external_message_id, $jid);
        }
    }
}
