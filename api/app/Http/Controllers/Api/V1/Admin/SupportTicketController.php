<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Support\SupportDesk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Support queue (docs/phase-6-customer-portal.md §0.3, §3.5): one shared queue for everyone with support.manage.
 * Open tickets come oldest first; one waiting longer than 24 hours is overdue, and the sidebar badge counts those.
 */
class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([SupportTicket::OPEN, SupportTicket::ANSWERED, SupportTicket::CLOSED, 'all'])],
            'overdue' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? SupportTicket::OPEN;

        $page = self::filtered($filters)
            ->with(['customer', 'booking.assignedStaff'])->withCount('messages')
            ->when($status === SupportTicket::OPEN, fn (Builder $q) => $q->oldest('last_customer_message_at')->oldest('id'), fn (Builder $q) => $q->latest('updated_at')->latest('id'))
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (SupportTicket $ticket) => self::row($ticket))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** The list filters, shared with the sidebar badge. */
    public static function filtered(array $filters): Builder
    {
        $status = $filters['status'] ?? SupportTicket::OPEN;

        return SupportTicket::query()
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when((bool) ($filters['overdue'] ?? false), fn (Builder $q) => $q->overdue())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('number', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => self::detail(SupportTicket::query()->findOrFail($id))]);
    }

    public function reply(Request $request, int $id, SupportDesk $desk): JsonResponse
    {
        $ticket = SupportTicket::query()->findOrFail($id);
        $body = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']])['body'];
        $desk->reply($ticket, $request->user('staff'), trim($body));

        return response()->json(['data' => self::detail($ticket->fresh())]);
    }

    public function close(Request $request, int $id, SupportDesk $desk): JsonResponse
    {
        $ticket = $desk->close(SupportTicket::query()->findOrFail($id), $request->user('staff'));

        return response()->json(['data' => self::detail($ticket)]);
    }

    /** @return array<string, mixed> */
    private static function row(SupportTicket $ticket): array
    {
        $booking = $ticket->booking;

        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'overdue' => $ticket->isOverdue(),
            'customer' => ['id' => $ticket->customer->id, 'name' => $ticket->customer->name, 'phone' => $ticket->customer->phone],
            'booking' => $booking ? [
                'id' => $booking->id, 'reference' => $booking->reference,
                'assigned_staff' => $booking->assignedStaff ? ['id' => $booking->assignedStaff->id, 'name' => $booking->assignedStaff->name] : null,
            ] : null,
            'messages_count' => $ticket->messages_count ?? $ticket->messages()->count(),
            'last_customer_message_at' => $ticket->last_customer_message_at->toIso8601String(),
            'last_staff_reply_at' => $ticket->last_staff_reply_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(SupportTicket $ticket): array
    {
        $ticket->load(['customer', 'booking.assignedStaff', 'messages.staff']);

        return self::row($ticket) + [
            'messages' => $ticket->messages->map(fn (SupportMessage $message) => [
                'id' => $message->id,
                'author' => $message->author,
                'staff' => $message->staff ? ['id' => $message->staff->id, 'name' => $message->staff->name] : null,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
