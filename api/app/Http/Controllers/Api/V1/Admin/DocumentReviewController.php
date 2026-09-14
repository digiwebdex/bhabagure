<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Portal\PortalDocumentController;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Staff;
use App\Models\TravellerDocument;
use App\Services\Documents\DocumentRefused;
use App\Services\Documents\TravellerDocuments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Document review (docs/phase-6-customer-portal.md §3.3, §3.7): passport scans and photos waiting for staff, oldest
 * first, on bookings this staff member can see. Verifying, rejecting and setting visa or insurance need bookings.update
 * and, for a sales agent, a booking they own — the same rule as every other booking action.
 */
class DocumentReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([TravellerDocument::UPLOADED, TravellerDocument::REJECTED, TravellerDocument::VERIFIED])]]);
        $staff = $request->user('staff');
        $status = $filters['status'] ?? TravellerDocument::UPLOADED;

        $page = self::queue($staff, $status)
            ->with(['traveller.booking.assignedStaff', 'reviewer'])
            ->when($status === TravellerDocument::UPLOADED, fn (Builder $q) => $q->oldest('uploaded_at')->oldest('id'), fn (Builder $q) => $q->latest('reviewed_at')->latest('id'))
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (TravellerDocument $document) => self::row($document, $staff))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Uploads in this state on bookings the staff member can see — the sidebar badge counts status uploaded. */
    public static function queue(Staff $staff, string $status = TravellerDocument::UPLOADED): Builder
    {
        return TravellerDocument::query()->whereIn('kind', TravellerDocument::UPLOADS)->where('status', $status)
            ->whereHas('traveller.booking', fn (Builder $booking) => $booking->visibleTo($staff));
    }

    public function file(Request $request, int $id, TravellerDocuments $documents): HttpResponse
    {
        $document = $this->document($request, $id);
        abort_if($document->path === null, Response::HTTP_NOT_FOUND);

        return PortalDocumentController::inline($documents->contents($document), (string) $document->mime, "{$document->kind}-{$document->booking_traveller_id}");
    }

    public function review(Request $request, int $id, TravellerDocuments $documents): JsonResponse
    {
        $document = $this->document($request, $id, work: true);
        $data = $request->validate([
            'decision' => ['required', Rule::in([TravellerDocument::VERIFIED, TravellerDocument::REJECTED])],
            'reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:300'],
        ]);

        try {
            $reviewed = $documents->review($document, $data['decision'] === TravellerDocument::VERIFIED, $data['reason'] ?? null, $request->user('staff'));
        } catch (DocumentRefused $e) {
            return response()->json(['message' => __("documents.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => self::row($reviewed->load(['traveller.booking.assignedStaff', 'reviewer']), $request->user('staff'))]);
    }

    /** Visa or insurance for one traveller. */
    public function setStatus(Request $request, int $travellerId, string $kind, TravellerDocuments $documents): JsonResponse
    {
        $staff = $request->user('staff');
        $traveller = BookingTraveller::query()->whereKey($travellerId)
            ->whereHas('booking', fn (Builder $booking) => $booking->visibleTo($staff))->with('booking')->firstOrFail();
        self::mayWork($staff, $traveller->booking);
        $data = $request->validate([
            'status' => ['required', Rule::in([TravellerDocument::PENDING, TravellerDocument::ISSUED_STATUS, TravellerDocument::NOT_REQUIRED])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $documents->setIssued($traveller, $kind, $data['status'], $data['note'] ?? null, $staff);

        return response()->json(['data' => TravellerDocuments::slots($traveller->fresh(), TravellerDocuments::onArrival($traveller->booking))]);
    }

    /** @return array<string, mixed> */
    private static function row(TravellerDocument $document, Staff $staff): array
    {
        $booking = $document->traveller->booking;

        return [
            'id' => $document->id,
            'kind' => $document->kind,
            'status' => $document->status,
            'source' => $document->source,
            'mime' => $document->mime,
            'note' => $document->note,
            'uploaded_at' => $document->uploaded_at?->toIso8601String(),
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewed_by' => $document->reviewer ? ['id' => $document->reviewer->id, 'name' => $document->reviewer->name] : null,
            'traveller' => ['id' => $document->traveller->id, 'full_name' => $document->traveller->full_name, 'is_lead' => $document->traveller->is_lead],
            'booking' => [
                'id' => $booking->id, 'reference' => $booking->reference, 'package_title_en' => $booking->package_title_en,
                'travel_start' => $booking->travel_start?->toDateString(), 'status' => $booking->status->value,
                'assigned_staff' => $booking->assignedStaff ? ['id' => $booking->assignedStaff->id, 'name' => $booking->assignedStaff->name] : null,
            ],
            'actions' => ['review' => $document->status === TravellerDocument::UPLOADED && self::canWork($staff, $booking)],
        ];
    }

    private function document(Request $request, int $id, bool $work = false): TravellerDocument
    {
        $staff = $request->user('staff');
        $document = TravellerDocument::query()->whereKey($id)->whereIn('kind', TravellerDocument::UPLOADS)
            ->whereHas('traveller.booking', fn (Builder $booking) => $booking->visibleTo($staff))->with('traveller.booking')->firstOrFail();
        if ($work) {
            self::mayWork($staff, $document->traveller->booking);
        }

        return $document;
    }

    private static function canWork(Staff $staff, Booking $booking): bool
    {
        return $staff->can('bookings.update') && (Booking::seesAll($staff) || $booking->assigned_staff_id === $staff->id);
    }

    private static function mayWork(Staff $staff, Booking $booking): void
    {
        abort_unless($staff->can('bookings.update'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
        if (! Booking::seesAll($staff) && $booking->assigned_staff_id !== $staff->id) {
            abort(response()->json(['message' => __('ownership.claim_first'), 'code' => 'claim_first'], Response::HTTP_CONFLICT));
        }
    }
}
