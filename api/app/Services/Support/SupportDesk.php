<?php

namespace App\Services\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentNumbers;
use App\Services\Notifications\NotificationPlanner;
use Illuminate\Support\Facades\DB;

/**
 * Support tickets (docs/phase-6-customer-portal.md §0.3, §3.5). A customer opens one in the portal, optionally about a
 * trip; the booking's owner (or the customer's) and whoever receives the alert are told. A staff reply goes back by
 * WhatsApp and email and shows in the portal. Message text is never copied into the audit log.
 */
final class SupportDesk
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly AuditLogger $audit,
        private readonly NotificationPlanner $planner,
    ) {}

    public function open(Customer $customer, ?Booking $booking, string $subject, string $body): SupportTicket
    {
        return DB::transaction(function () use ($customer, $booking, $subject, $body) {
            $ticket = SupportTicket::query()->create([
                'number' => $this->numbers->supportTicketNumber(), 'customer_id' => $customer->id, 'booking_id' => $booking?->id,
                'subject' => $subject, 'status' => SupportTicket::OPEN, 'last_customer_message_at' => now(),
            ]);
            $message = $ticket->messages()->create(['author' => 'customer', 'body' => $body]);
            $this->audit->record('support.ticket_opened', $customer, $ticket, array_filter(['booking' => $booking?->reference]));
            $this->planner->supportTicketOpened($ticket, $message);

            return $ticket;
        });
    }

    /** The customer writes again: the ticket is ours to answer, even if it was closed. */
    public function customerMessage(SupportTicket $ticket, Customer $customer, string $body): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $customer, $body) {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $message = $locked->messages()->create(['author' => 'customer', 'body' => $body]);
            $reopened = $locked->status === SupportTicket::CLOSED;
            $locked->forceFill(['status' => SupportTicket::OPEN, 'last_customer_message_at' => now(), 'closed_at' => null, 'closed_by_staff_id' => null])->save();
            $this->audit->record($reopened ? 'support.ticket_reopened' : 'support.customer_message', $customer, $locked);

            return $message;
        });
    }

    public function reply(SupportTicket $ticket, Staff $staff, string $body): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $staff, $body) {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $message = $locked->messages()->create(['author' => 'staff', 'staff_id' => $staff->id, 'body' => $body]);
            $locked->forceFill(['status' => SupportTicket::ANSWERED, 'last_staff_reply_at' => now(), 'closed_at' => null, 'closed_by_staff_id' => null])->save();
            $this->audit->record('support.replied', $staff, $locked);
            $this->planner->supportReplied($locked, $message);

            return $message;
        });
    }

    public function close(SupportTicket $ticket, Staff $staff): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $staff) {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SupportTicket::CLOSED) {
                $locked->forceFill(['status' => SupportTicket::CLOSED, 'closed_at' => now(), 'closed_by_staff_id' => $staff->id])->save();
                $this->audit->record('support.ticket_closed', $staff, $locked);
            }

            return $locked;
        });
    }
}
