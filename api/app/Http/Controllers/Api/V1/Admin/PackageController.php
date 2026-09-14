<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PackageStatus;
use App\Exceptions\NotReadyToPublish;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PackageRequest;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\Booking;
use App\Models\PackageInclusion;
use App\Models\Tag;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    private const RELATIONS = ['destination', 'itineraryDays', 'inclusions', 'tags', 'images.media'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PackageStatus::class)],
            'destination' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $packages = TourPackage::query()
            ->with(['destination', 'images' => fn ($query) => $query->where('is_cover', true)->with('media')])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['destination'] ?? null, fn ($query, $slug) => $query->whereRelation('destination', 'slug', $slug))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('title_en', 'like', "%{$search}%")->orWhere('title_bn', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $packages->map(AdminContent::packageSummary(...))]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => AdminContent::package(TourPackage::query()->with(self::RELATIONS)->findOrFail($id))]);
    }

    public function store(PackageRequest $request): JsonResponse
    {
        $package = DB::transaction(function () use ($request) {
            $package = TourPackage::query()->create([
                ...$this->fields($request),
                'status' => PackageStatus::Draft,
                'sort_order' => (int) TourPackage::query()->max('sort_order') + 1,
            ]);
            $package->forceFill(['created_by_staff_id' => $request->user('staff')->id, 'updated_by_staff_id' => $request->user('staff')->id])->save();
            $this->saveChildren($package, $request);

            return $package;
        });

        $this->audit->record('cms.package.created', $request->user('staff'), $package);

        return response()->json(['data' => AdminContent::package($package->load(self::RELATIONS))], 201);
    }

    public function update(PackageRequest $request, int $id): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($id);

        try {
            DB::transaction(function () use ($package, $request) {
                $package->fill($this->fields($request));
                $package->forceFill(['updated_by_staff_id' => $request->user('staff')->id])->save();
                $this->saveChildren($package, $request);

                // A live package must stay publishable: an edit can't silently strip its photos or Bangla title.
                if ($package->status === PackageStatus::Published) {
                    NotReadyToPublish::throwIfAny(self::publishProblems($package->load(self::RELATIONS)));
                }
            });
        } catch (NotReadyToPublish $exception) {
            return $exception->render();
        }

        $this->audit->record('cms.package.updated', $request->user('staff'), $package, ['fields' => array_keys($request->validated())]);
        $this->revalidate($package);

        return response()->json(['data' => AdminContent::package($package->fresh(self::RELATIONS))]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $package = TourPackage::query()->with(self::RELATIONS)->findOrFail($id);

        NotReadyToPublish::throwIfAny(self::publishProblems($package));

        return $this->transition($request, $package, PackageStatus::Published);
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, TourPackage::query()->findOrFail($id), PackageStatus::Draft);
    }

    /** No longer sold, but kept for the bookings and invoices that point at it. */
    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, TourPackage::query()->findOrFail($id), PackageStatus::Archived);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($id);

        if (Booking::withTrashed()->where('tour_package_id', $package->id)->exists()) {
            return response()->json(['message' => __('cms.has_bookings'), 'code' => 'has_bookings'], 409);
        }

        $package->delete();
        $this->audit->record('cms.package.deleted', $request->user('staff'), $package);
        $this->revalidate($package);

        return response()->json(['data' => null]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:tour_packages,id']])['ids'];

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                TourPackage::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
        RevalidateWebsite::dispatch(['packages']);

        return response()->json(['data' => ['ids' => $ids]]);
    }

    /** @return list<string> */
    public static function publishProblems(TourPackage $package): array
    {
        $problems = [];
        if (blank($package->title_bn)) {
            $problems[] = __('cms.publish_requirements.title_bn');
        }
        // The website card shows the price and the duration pill; without them it breaks.
        if ((float) $package->regular_price <= 0) {
            $problems[] = __('cms.publish_requirements.price');
        }
        if ((int) $package->duration_days < 1) {
            $problems[] = __('cms.publish_requirements.duration');
        }
        if ($package->itineraryDays->isEmpty()) {
            $problems[] = __('cms.publish_requirements.itinerary');
        }
        if ($package->inclusions->where('kind', PackageInclusion::INCLUDE)->isEmpty()) {
            $problems[] = __('cms.publish_requirements.includes');
        }
        if ($package->images->isEmpty()) {
            $problems[] = __('cms.publish_requirements.images');
        }

        return $problems;
    }

    private function transition(Request $request, TourPackage $package, PackageStatus $status): JsonResponse
    {
        $package->forceFill([
            'status' => $status,
            'published_at' => $status === PackageStatus::Published ? ($package->published_at ?? now()) : $package->published_at,
            'updated_by_staff_id' => $request->user('staff')->id,
        ])->save();

        $this->audit->record("cms.package.{$status->value}", $request->user('staff'), $package);
        $this->revalidate($package);

        return response()->json(['data' => AdminContent::package($package->fresh(self::RELATIONS))]);
    }

    private function fields(PackageRequest $request): array
    {
        return collect($request->validated())->except(['itinerary', 'includes', 'excludes', 'activities', 'trip_types'])->all();
    }

    private function saveChildren(TourPackage $package, PackageRequest $request): void
    {
        $data = $request->validated();

        $package->itineraryDays()->delete();
        $package->itineraryDays()->createMany(collect($data['itinerary'])->sortBy('day_number')->values()->all());

        $package->inclusions()->delete();
        foreach ([PackageInclusion::INCLUDE => $data['includes'], PackageInclusion::EXCLUDE => $data['excludes']] as $kind => $items) {
            foreach (array_values($items) as $position => $item) {
                $package->inclusions()->create([...$item, 'kind' => $kind, 'sort_order' => $position + 1]);
            }
        }

        if ($request->has('activities') || $request->has('trip_types')) {
            $package->syncTagNames([Tag::ACTIVITY => $data['activities'] ?? [], Tag::TRIP_TYPE => $data['trip_types'] ?? []]);
        }
    }

    private function revalidate(TourPackage $package): void
    {
        // Departures show package titles, so both tags refresh.
        RevalidateWebsite::dispatch(['packages', 'departures']);
    }
}
