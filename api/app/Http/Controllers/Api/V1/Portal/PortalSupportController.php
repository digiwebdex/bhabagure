<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Support\SupportDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support (docs/phase-6-customer-portal.md §3.5): the customer's tickets, a new one optionally about one of their trips,
 * and the conversation. Staff appear by first name only.
 */
class PortalSupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $tickets = SupportTicket::query()->where('customer_id', $customer->id)->with(['booking', 'messages'])->latest('updated_at')->latest('id')->get();
        $en = app()->getLocale() === 'en';

        return response()->json(['data' => [
            'tickets' => $tickets->map(fn (SupportTicket $ticket) => self::summary($ticket))->all(),
            // The trips a new ticket can be about.
            'bookings' => PortalTripController::bookingsOf($customer)->latest('created_at')->get()->map(fn (Booking $b) => [
                'reference' => $b->reference,
                'title' => $en ? $b->package_title_en : ($b->package_title_bn ?: $b->package_title_en),
            ])->all(),
        ]]);
    }

    public function store(Request $request, SupportDesk $desk): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $data = $request->validate([
            'booking_reference' => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:2', 'max:4000'],
        ]);
        $booking = null;
        if (filled($data['booking_reference'] ?? null)) {
            $booking = PortalTripController::bookingsOf($customer)->where('reference', $data['booking_reference'])->first();
            abort_if($booking === null, Response::HTTP_NOT_FOUND, __('portal.not_found'));
        }

        $ticket = $desk->open($customer, $booking, trim($data['subject']), trim($data['body']));

        return response()->json(['data' => self::detail($ticket)], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return response()->json(['data' => self::detail($this->ticket($request, $number))]);
    }

    public function message(Request $request, string $number, SupportDesk $desk): JsonResponse
    {
        $ticket = $this->ticket($request, $number);
        $body = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']])['body'];
        $desk->customerMessage($ticket, PortalTripController::customer($request), trim($body));

        return response()->json(['data' => self::detail($ticket->fresh())], Response::HTTP_CREATED);
    }

    private function ticket(Request $request, string $number): SupportTicket
    {
        $ticket = SupportTicket::query()->where('customer_id', PortalTripController::customer($request)->id)->where('number', $number)->first();
        abort_if($ticket === null, Response::HTTP_NOT_FOUND, __('portal.not_found'));

        return $ticket;
    }

    /** @return array<string, mixed> */
    private static function summary(SupportTicket $ticket): array
    {
        $ticket->loadMissing(['booking', 'messages.staff']);
        $last = $ticket->messages->last();

        return [
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'bookingReference' => $ticket->booking?->reference,
            'createdAt' => $ticket->created_at->toIso8601String(),
            'lastMessage' => $last ? self::messageRow($last) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(SupportTicket $ticket): array
    {
        $ticket->load(['booking', 'messages.staff']);

        return self::summary($ticket) + ['messages' => $ticket->messages->map(fn (SupportMessage $m) => self::messageRow($m))->all()];
    }

    /** @return array<string, mixed> */
    private static function messageRow(SupportMessage $message): array
    {
        return [
            'id' => $message->id,
            'author' => $message->author,
            'staffName' => $message->author === 'staff' ? (strtok((string) $message->staff?->name, ' ') ?: null) : null,
            'body' => $message->body,
            'at' => $message->created_at->toIso8601String(),
        ];
    }
}
