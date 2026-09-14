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
        $departure = $package->departures()->create($this->validated($request));

        return $this->respond($request, $departure, 'cms.departure.created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $departure = PackageDeparture::query()->findOrFail($id);
        $departure->update($this->validated($request, $departure));

        return $this->respond($request, $departure, 'cms.departure.updated');
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
