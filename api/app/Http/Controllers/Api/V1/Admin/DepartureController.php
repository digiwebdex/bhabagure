<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Scheduled group departures. Seats booked are derived from confirmed bookings, never typed in. */
class DepartureController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(int $packageId): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($packageId);
        $departures = $this->withBookedSeats($package->departures()->getQuery())->orderBy('departs_on')->get();

        return response()->json(['data' => $departures->map(AdminContent::departure(...))]);
    }

    public function store(Request $request, int $packageId): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($packageId);
        $data = $this->validated($request);
        $departure = DB::transaction(fn () => $this->featuring($package->departures()->create($data), $data));

        return $this->respond($request, $departure, 'cms.departure.created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $departure = PackageDeparture::query()->findOrFail($id);
        $data = $this->validated($request, $departure);
        DB::transaction(fn () => $this->featuring(tap($departure)->update($data), $data));

        return $this->respond($request, $departure, 'cms.departure.updated');
    }

    /**
     * "Show on the card" (docs/departure-prices.md): this departure becomes the one the website's card shows and the booking
     * form starts on; `featured: false` leaves the card to the next date with seats.
     */
    public function feature(Request $request, int $id): JsonResponse
    {
        $departure = PackageDeparture::query()->findOrFail($id);
        $data = $request->validate(['featured' => ['required', 'boolean']]);
        DB::transaction(function () use ($departure, $data) {
            $departure->update(['is_featured' => (bool) $data['featured']]);
            $this->featuring($departure, ['is_featured' => (bool) $data['featured']]);
        });

        return $this->respond($request, $departure, $data['featured'] ? 'cms.departure.featured' : 'cms.departure.unfeatured');
    }

    /**
     * At most one featured departure a package: featuring this one takes the mark off the others.
     *
     * @param  array<string, mixed>  $data
     */
    private function featuring(PackageDeparture $departure, array $data): PackageDeparture
    {
        if ($data['is_featured'] ?? false) {
            PackageDeparture::query()->where('tour_package_id', $departure->tour_package_id)->whereKeyNot($departure->id)
                ->where('is_featured', true)->update(['is_featured' => false]);
        }

        return $departure;
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $departure = PackageDeparture::query()->findOrFail($id);

        if ($departure->bookings()->withTrashed()->exists()) {
            return response()->json(['message' => __('cms.has_bookings'), 'code' => 'has_bookings'], 409);
        }

        $departure->delete();
        $this->audit->record('cms.departure.deleted', $request->user('staff'), $departure);
        RevalidateWebsite::dispatch(['departures']);

        return response()->json(['data' => null]);
    }

    private function validated(Request $request, ?PackageDeparture $departure = null): array
    {
        return $request->validate([
            'departs_on' => ['required', 'date_format:Y-m-d', $departure === null ? 'after_or_equal:today' : 'date'],
            'returns_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:departs_on'],
            'seats_total' => ['required', 'integer', 'between:1,500'],
            // A group tour's price per person on this date; blank for the package's (docs/departure-prices.md).
            'price' => ['nullable', 'numeric', 'min:1', 'max:99999999', 'decimal:0,2'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_guaranteed' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['scheduled', 'closed', 'departed', 'cancelled'])],
            'group_leader_staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function withBookedSeats(Builder $query): Builder
    {
        return $query->withSum(['bookings as booked_pax' => fn (Builder $bookings) => $bookings->whereIn('status', ['confirmed', 'completed'])], 'pax_count');
    }

    private function respond(Request $request, PackageDeparture $departure, string $action, int $status = 200): JsonResponse
    {
        $this->audit->record($action, $request->user('staff'), $departure);
        RevalidateWebsite::dispatch(['departures']);

        $fresh = $this->withBookedSeats(PackageDeparture::query())->findOrFail($departure->id);

        return response()->json(['data' => AdminContent::departure($fresh)], $status);
    }
}
