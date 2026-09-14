<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\TravellerDocument;
use App\Services\Documents\DocumentRefused;
use App\Services\Documents\TravellerDocuments;
use App\Services\Portal\TripReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents (docs/phase-6-customer-portal.md §3.3): per traveller on the customer's upcoming trips, the passport scan and
 * photo to upload, a passport number to add while none is on file, and the visa and insurance statuses staff set.
 * Passport digits never come back; an upload is served back only to its customer, never cached.
 */
class PortalDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = PortalTripController::bookingsOf(PortalTripController::customer($request))
            ->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Confirmed])
            ->with(['travellers.documents', 'package.destination'])->get()
            ->filter(fn (Booking $b) => TripReadiness::isUpcoming($b))
            ->sortBy(fn (Booking $b) => $b->travel_start?->toDateString() ?? '9999')->values();
        $en = app()->getLocale() === 'en';

        $trips = $bookings->map(fn (Booking $booking) => [
            'reference' => $booking->reference,
            'title' => $en ? $booking->package_title_en : ($booking->package_title_bn ?: $booking->package_title_en),
            'travelStart' => $booking->travel_start?->toDateString(),
            'travellers' => $booking->travellers->sortBy('sort_order')->map(fn (BookingTraveller $t) => [
                'id' => $t->id,
                'name' => $t->full_name,
                'isLead' => $t->is_lead,
                'passportOnFile' => filled($t->passport_number),
                'documents' => TravellerDocuments::slots($t, TravellerDocuments::onArrival($booking)),
            ])->values()->all(),
        ])->all();

        // What the customer can still do: an upload not yet sent (or rejected), and a passport number to add.
        $toDo = collect($trips)->flatMap(fn (array $trip) => $trip['travellers'])->sum(fn (array $t) => ($t['passportOnFile'] ? 0 : 1)
            + collect($t['documents'])->whereIn('kind', TravellerDocument::UPLOADS)->whereIn('status', ['missing', TravellerDocument::REJECTED])->count());

        return response()->json(['data' => ['trips' => $trips, 'toDo' => $toDo]]);
    }

    public function upload(Request $request, int $travellerId, string $kind, TravellerDocuments $documents): JsonResponse
    {
        $traveller = $this->traveller($request, $travellerId);
        $file = $request->validate(['file' => TravellerDocuments::rules($kind)])['file'];

        try {
            $documents->upload($traveller, $kind, $file, PortalTripController::customer($request));
        } catch (DocumentRefused $e) {
            return response()->json(['message' => __("documents.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => TravellerDocuments::slots($traveller->fresh(), TravellerDocuments::onArrival($traveller->booking))], Response::HTTP_CREATED);
    }

    public function file(Request $request, int $travellerId, string $kind, TravellerDocuments $documents): HttpResponse
    {
        $traveller = $this->traveller($request, $travellerId);
        $document = TravellerDocument::query()->where('booking_traveller_id', $traveller->id)->where('kind', $kind)->whereNotNull('path')->first();
        abort_if($document === null, Response::HTTP_NOT_FOUND, __('portal.not_found'));

        return self::inline($documents->contents($document), (string) $document->mime, "{$kind}-{$traveller->id}");
    }

    public function passport(Request $request, int $travellerId, TravellerDocuments $documents): JsonResponse
    {
        $traveller = $this->traveller($request, $travellerId);
        $request->merge(['passport_number' => strtoupper((string) preg_replace('/\s+/', '', (string) $request->input('passport_number')))]);
        $data = $request->validate([
            'passport_number' => ['required', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'passport_expiry' => ['required', 'date_format:Y-m-d', 'after:'.($traveller->booking->travel_end ?? $traveller->booking->travel_start ?? now('Asia/Dhaka'))->toDateString()],
        ], ['passport_expiry.after' => __('booking.passport_expiry_after_travel')]);

        try {
            $documents->addPassportNumber($traveller, $data['passport_number'], $data['passport_expiry'], PortalTripController::customer($request));
        } catch (DocumentRefused $e) {
            return response()->json(['message' => __("documents.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => ['passportOnFile' => true]]);
    }

    /** Decrypted file bytes for the viewer who asked, never stored by a browser or proxy. */
    public static function inline(string $bytes, string $mime, string $name): HttpResponse
    {
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };

        return response($bytes)
            ->header('Content-Type', $mime ?: 'application/octet-stream')
            ->header('Content-Disposition', "inline; filename=\"{$name}.{$extension}\"")
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    }

    /** A traveller on one of the customer's open bookings; anything else is 404. */
    private function traveller(Request $request, int $travellerId): BookingTraveller
    {
        $traveller = BookingTraveller::query()->whereKey($travellerId)
            ->whereHas('booking', fn ($q) => $q->where('customer_id', PortalTripController::customer($request)->id)
                ->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Confirmed]))
            ->with('booking')->first();
        abort_if($traveller === null, Response::HTTP_NOT_FOUND, __('portal.not_found'));

        return $traveller;
    }
}
