<?php

namespace App\Services\Inbox;

use App\Enums\LeadSource;
use App\Jobs\MarkInboxRead;
use App\Jobs\SendInboxMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** What staff do in the inbox (docs/admin-inbox.md): reply, read, assign, close, and tie a chat to a customer. */
final class InboxDesk
{
    public function __construct(private readonly InboxRecorder $recorder, private readonly AuditLogger $audit) {}

    /**
     * A reply: text, a file, or both. It is saved at once as pending and sent by SendInboxMessage; the one replying
     * takes an unassigned chat.
     *
     * @throws ValidationException
     */
    public function reply(Conversation $conversation, Staff $staff, ?string $body, ?UploadedFile $file): ConversationMessage
    {
        $body = $body === null ? null : trim($body);
        if (($body === null || $body === '') && $file === null) {
            throw ValidationException::withMessages(['body' => [__('inbox.empty_reply')]]);
        }
        if (! $conversation->canReply()) {
            throw ValidationException::withMessages(['body' => [__('inbox.messenger_window')]]);
        }
        if ($conversation->channel === Conversation::WHATSAPP && ! WhatsAppInbox::enabled()) {
            throw ValidationException::withMessages(['body' => [__('inbox.whatsapp_off')]]);
        }
        if ($conversation->channel === Conversation::MESSENGER && ! MessengerSettings::connected()) {
            throw ValidationException::withMessages(['body' => [__('inbox.messenger_off')]]);
        }

        $path = $file === null ? null : InboxFiles::store((string) file_get_contents($file->getRealPath()));
        try {
            $message = DB::transaction(function () use ($conversation, $staff, $body, $file, $path) {
                $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $mime = $file === null ? null : (string) $file->getMimeType();
                $message = $locked->messages()->create([
                    'direction' => ConversationMessage::OUT,
                    'origin' => ConversationMessage::STAFF,
                    'staff_id' => $staff->id,
                    'body' => $body === '' ? null : $body,
                    'attachment_kind' => $mime === null ? null : InboxFiles::kind($mime),
                    'attachment_path' => $path,
                    'attachment_mime' => $mime === null ? null : Str::limit($mime, 100, ''),
                    'attachment_name' => $file === null ? null : Str::limit(basename($file->getClientOriginalName()), 180, ''),
                    'attachment_bytes' => $file?->getSize(),
                    'status' => 'pending',
                    'sent_at' => now(),
                ]);
                $this->recorder->touch($locked, $message);
                // Answering a chat means it's been read, and an unassigned one becomes the replier's.
                $locked->forceFill(['unread_count' => 0, 'assigned_staff_id' => $locked->assigned_staff_id ?? $staff->id])->save();

                return $message;
            });
        } catch (Throwable $e) {
            InboxFiles::delete($path);
            throw $e;
        }
        SendInboxMessage::dispatch($message->id);

        return $message;
    }

    /**
     * A message a staff member wrote elsewhere (a request reply, "Send WhatsApp" on a booking) sent from the main number
     * while the automated messages' number isn't in use (docs/admin-inbox.md §6). It goes into that customer's chat,
     * started if need be, so the thread is complete and the reply is paced like every other.
     *
     * @throws ValidationException
     */
    public function replyToNumber(string $phone, ?Customer $customer, string $body, Staff $staff): ConversationMessage
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['channel' => Conversation::WHATSAPP, 'external_id' => $phone],
            ['phone' => $phone, 'jid' => $phone.'@s.whatsapp.net', 'name' => $customer?->name, 'customer_id' => $customer?->id, 'status' => Conversation::OPEN, 'unread_count' => 0],
        );

        return $this->reply($conversation, $staff, $body, null);
    }

    public function markRead(Conversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
            return;
        }
        $conversation->forceFill(['unread_count' => 0])->save();
        MarkInboxRead::dispatch($conversation->id);
    }

    public function assign(Conversation $conversation, ?Staff $to, Staff $by): void
    {
        $conversation->forceFill(['assigned_staff_id' => $to?->id])->save();
        $this->audit->record('inbox.assigned', $by, $conversation, ['to' => $to?->id]);
    }

    public function setStatus(Conversation $conversation, string $status, Staff $by): void
    {
        $conversation->forceFill(['status' => $status] + ($status === Conversation::CLOSED ? ['unread_count' => 0] : []))->save();
        $this->audit->record($status === Conversation::CLOSED ? 'inbox.closed' : 'inbox.reopened', $by, $conversation);
    }

    public function link(Conversation $conversation, ?Customer $customer, Staff $by): void
    {
        $conversation->forceFill(['customer_id' => $customer?->id])->save();
        $this->audit->record('inbox.customer_linked', $by, $conversation, ['customer_id' => $customer?->id]);
    }

    /**
     * A new lead from the chat, owned by the staff member; WhatsApp gives the number, Messenger needs it typed in. An
     * existing customer with that number is linked instead of duplicated.
     */
    public function createLead(Conversation $conversation, string $name, string $phone, Staff $by): Customer
    {
        return DB::transaction(function () use ($conversation, $name, $phone, $by) {
            $customer = Customer::query()->where('phone', $phone)->first();
            if (! $customer) {
                $customer = Customer::query()->create([
                    'name' => $name, 'phone' => $phone, 'stage' => 'lead', 'assigned_staff_id' => $by->id, 'locale' => 'bn',
                    'source' => $conversation->channel === Conversation::MESSENGER ? LeadSource::Facebook->value : LeadSource::WhatsApp->value,
                ]);
                $this->audit->record('customer.created', $by, $customer, ['source' => $customer->source, 'via' => 'inbox']);
            }
            $this->link($conversation, $customer, $by);

            return $customer;
        });
    }
}
