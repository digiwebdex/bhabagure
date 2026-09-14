<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBooking;
use App\Models\Booking;
use App\Models\BookingTicket;
use App\Models\Staff;
use App\Services\Documents\BookingTickets;
use App\Services\Documents\DocumentRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-tickets on a booking (closing a Phase 6 gap, docs/phase-6-customer-portal.md §8). Recording and voiding need
 * bookings.update and, for a sales agent, a booking they own — the rule every booking action follows. Every action
 * answers with the updated booking, like the rest of the booking screen.
 */
class BookingTicketController extends Controller
{
    public function store(Request $request, int $id, BookingTickets $tickets): JsonResponse
    {
        $booking = $this->booking($request, $id, work: true);
        $request->merge(['pnr' => strtoupper(trim((string) $request->input('pnr'))), 'ticket_number' => preg_replace('/\s+/', '', (string) $request->input('ticket_number'))]);
        $data = $request->validate([
            'booking_traveller_id' => ['required', 'integer', Rule::exists('booking_travellers', 'id')->where('booking_id', $booking->id)],
            'airline' => ['required', 'string', 'max:80'],
            'pnr' => ['required', 'string', 'regex:/^[A-Z0-9]{5,12}$/'],
            'ticket_number' => ['required', 'string', 'regex:/^[0-9-]{8,20}$/'],
            'route' => ['nullable', 'string', 'max:80'],
            'departs_on' => ['nullable', 'date_format:Y-m-d'],
            'file' => ['nullable', 'file', 'mimes:'.implode(',', BookingTickets::MIMES), 'max:'.BookingTickets::MAX_KB],
        ]);

        $traveller = $booking->travellers()->whereKey($data['booking_traveller_id'])->firstOrFail();
        $tickets->issue($booking, $traveller, $data, $request->file('file'), $request->user('staff'));

        return $this->detail($request, $booking, Response::HTTP_CREATED);
    }

    public function void(Request $request, int $ticketId, BookingTickets $tickets): JsonResponse
    {
        $ticket = BookingTicket::query()->findOrFail($ticketId);
        $booking = $this->booking($request, $ticket->booking_id, work: true);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        try {
            $tickets->void($ticket, $reason, $request->user('staff'));
        } catch (DocumentRefused $e) {
            return response()->json(['message' => __("documents.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return $this->detail($request, $booking);
    }

    public function file(Request $request, int $ticketId, BookingTickets $tickets): HttpResponse
    {
        $ticket = BookingTicket::query()->whereNotNull('path')->findOrFail($ticketId);
        $this->booking($request, $ticket->booking_id);

        return PortalDocumentController::inline($tickets->contents($ticket), (string) $ticket->mime, "e-ticket-{$ticket->ticket_number}");
    }

    private function booking(Request $request, int $id, bool $work = false): Booking
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $booking = Booking::query()->visibleTo($staff)->findOrFail($id);
        if ($work) {
            abort_unless($staff->can('bookings.update'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
            if (! Booking::seesAll($staff) && $booking->assigned_staff_id !== $staff->id) {
                abort(response()->json(['message' => __('ownership.claim_first'), 'code' => 'claim_first'], Response::HTTP_CONFLICT));
            }
        }

        return $booking;
    }

    private function detail(Request $request, Booking $booking, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json(['data' => AdminBooking::detail($booking->fresh(), $request->user('staff'))], $status);
    }
}
