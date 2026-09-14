<?php

namespace App\Services\Portal;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingTicket;
use App\Models\BookingTraveller;
use App\Models\TravellerDocument;
use App\Services\Documents\TravellerDocuments;
use Carbon\CarbonImmutable;

/**
 * How ready an upcoming trip is (docs/phase-6-customer-portal.md §2 #2). Only what the system knows is checked — never a
 * literal percentage. Each check names the travellers it still waits on, so the portal can say who and what.
 */
final class TripReadiness
{
    /** @return array{checks: list<array{key: string, done: bool, waitingOn: list<string>}>, done: int, total: int} */
    public static function for(Booking $booking): array
    {
        $booking->loadMissing(['travellers.documents', 'tickets', 'package.destination']);
        $travellers = $booking->travellers->sortBy('sort_order')->values();
        $onArrival = TravellerDocuments::onArrival($booking);
        $waiting = fn (callable $missing) => $travellers->filter($missing)->map(fn (BookingTraveller $t) => $t->full_name)->values()->all();
        // A slot nobody has touched counts as its default: visa and insurance are "not required" on an on-arrival trip.
        $status = fn (BookingTraveller $t, string $kind) => $t->documents->firstWhere('kind', $kind)?->status ?? TravellerDocuments::defaultStatus($kind, $onArrival);

        $check = fn (string $key, array $waitingOn) => ['key' => $key, 'done' => $waitingOn === [], 'waitingOn' => $waitingOn];
        $checks = [
            ['key' => 'paid', 'done' => (float) $booking->due_amount <= 0, 'waitingOn' => []],
            $check('passports', $waiting(fn (BookingTraveller $t) => blank($t->passport_number))),
            $check('documents', $waiting(fn (BookingTraveller $t) => collect(TravellerDocument::UPLOADS)->contains(fn (string $kind) => $status($t, $kind) !== TravellerDocument::VERIFIED))),
        ];
        foreach (TravellerDocument::ISSUED as $kind) {
            $checks[] = $check($kind, $waiting(fn (BookingTraveller $t) => ! in_array($status($t, $kind), [TravellerDocument::ISSUED_STATUS, TravellerDocument::NOT_REQUIRED], true)));
        }

        // E-tickets: on packages that include the airfare, and on any trip where staff have recorded a ticket.
        $tickets = $booking->tickets->filter(fn (BookingTicket $ticket) => $ticket->voided_at === null);
        if ($booking->package?->includes_airfare || $booking->tickets->isNotEmpty()) {
            $checks[] = $check('etickets', $waiting(fn (BookingTraveller $t) => ! $tickets->contains('booking_traveller_id', $t->id)));
        }

        return [
            'checks' => $checks,
            'done' => count(array_filter($checks, fn (array $check) => $check['done'])),
            'total' => count($checks),
        ];
    }

    /** Inquiry or confirmed, and not yet travelled (Dhaka). */
    public static function isUpcoming(Booking $booking): bool
    {
        if (! in_array($booking->status, [BookingStatus::Inquiry, BookingStatus::Confirmed], true)) {
            return false;
        }
        $last = $booking->travel_end ?? $booking->travel_start;

        return $last === null || $last->toDateString() >= self::today();
    }

    /** Whole days from today (Dhaka) to departure; null without a date or once it has started. */
    public static function daysToGo(Booking $booking): ?int
    {
        if ($booking->travel_start === null || $booking->travel_start->toDateString() < self::today()) {
            return null;
        }

        return (int) CarbonImmutable::parse(self::today())->diffInDays(CarbonImmutable::parse($booking->travel_start->toDateString()));
    }

    private static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }
}
