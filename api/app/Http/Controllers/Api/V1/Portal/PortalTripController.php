<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PackageItineraryDay;
use App\Services\Portal\TripReadiness;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * My trips (docs/phase-6-customer-portal.md §3.2): the bookings where the signed-in customer is the account holder.
 * Someone else's booking and a missing one both answer 404. Paying the balance uses the website's booking payment
 * endpoint, which already accepts the owner's session.
 */
class PortalTripController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = self::bookingsOf(self::customer($request))->get();
        $invoices = self::issuedInvoices($bookings->pluck('id')->all());

        // Upcoming first, soonest first; then everything else, most recent first.
        [$upcoming, $past] = $bookings->partition(fn (Booking $b) => TripReadiness::isUpcoming($b));
        $ordered = $upcoming->sortBy(fn (Booking $b) => $b->travel_start?->toDateString() ?? '9999')
            ->concat($past->sortByDesc(fn (Booking $b) => $b->travel_start?->toDateString() ?? $b->created_at->toDateString()))
            ->values();

        $next = $upcoming->filter(fn (Booking $b) => $b->travel_start !== null)->sortBy(fn (Booking $b) => $b->travel_start->toDateString())->first();

        return response()->json(['data' => [
            'next' => $next ? self::summary($next, $invoices) + ['readiness' => TripReadiness::for($next), 'daysToGo' => TripReadiness::daysToGo($next)] : null,
            'trips' => $ordered->map(fn (Booking $b) => self::summary($b, $invoices))->all(),
        ]]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $booking = self::bookingsOf(self::customer($request))->where('reference', $reference)->first();
        abort_if($booking === null, Response::HTTP_NOT_FOUND, __('booking.not_found'));

        $en = app()->getLocale() === 'en';
        $days = $booking->tour_package_id
            ? PackageItineraryDay::query()->where('tour_package_id', $booking->tour_package_id)->orderBy('day_number')->get()
            : collect();

        return response()->json(['data' => PublicBooking::make($booking) + [
            'upcoming' => TripReadiness::isUpcoming($booking),
            'daysToGo' => TripReadiness::daysToGo($booking),
            'readiness' => TripReadiness::for($booking),
            // The package's current plan, labelled as the planned itinerary: the booking doesn't freeze it.
            'itinerary' => $days->map(fn (PackageItineraryDay $day) => [
                'day' => $day->day_number,
                'title' => $day->localized('title')[$en ? 'en' : 'bn'],
                'body' => $day->localized('body')[$en ? 'en' : 'bn'],
            ])->all(),
        ]]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Booking> */
    public static function bookingsOf(Customer $customer)
    {
        return Booking::query()->where('customer_id', $customer->id);
    }

    public static function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }

    /**
     * @param  list<int>  $bookingIds
     * @return Collection<int, Invoice> the latest issued invoice per booking, keyed by booking id
     */
    private static function issuedInvoices(array $bookingIds): Collection
    {
        return Invoice::query()->whereIn('booking_id', $bookingIds)->where('status', Invoice::ISSUED)->orderBy('id')->get()->keyBy('booking_id');
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<string, mixed>
     */
    private static function summary(Booking $booking, Collection $invoices): array
    {
        $en = app()->getLocale() === 'en';
        $invoice = $invoices->get($booking->id);

        return [
            'reference' => $booking->reference,
            'title' => $en ? $booking->package_title_en : ($booking->package_title_bn ?: $booking->package_title_en),
            'status' => $booking->status->value,
            'paymentStatus' => $booking->payment_status,
            'travelStart' => $booking->travel_start?->toDateString(),
            'travelEnd' => $booking->travel_end?->toDateString(),
            'pax' => $booking->pax_count,
            'total' => Money::toNumber($booking->total_amount),
            'paid' => Money::toNumber($booking->paid_amount),
            'due' => Money::toNumber($booking->due_amount),
            'upcoming' => TripReadiness::isUpcoming($booking),
            'invoice' => $invoice ? [
                'number' => $invoice->invoice_number,
                'url' => url("/api/v1/public/invoices/{$invoice->share_token}"),
                'pdfUrl' => url("/api/v1/public/invoices/{$invoice->share_token}/pdf"),
            ] : null,
        ];
    }
}
