<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PackageStatus;
use App\Exceptions\NotReadyToPublish;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\PackageImage;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** A package's photos: attach from the media library, reorder, choose the cover, remove. */
class PackageImageController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, int $packageId): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($packageId);
        $data = $request->validate([
            'media_id' => ['required', 'integer', 'exists:media,id',
                Rule::unique('package_images', 'media_id')->where('tour_package_id', $package->id)],
        ]);

        DB::transaction(function () use ($package, $data) {
            $isFirst = ! $package->images()->exists();
            $package->images()->create([
                'media_id' => $data['media_id'],
                'sort_order' => (int) $package->images()->max('sort_order') + 1,
                'is_cover' => $isFirst,
            ]);
        });

        return $this->respond($request, $package, 'cms.package.image_added', 201);
    }

    /** Body: { "image_ids": [5, 2, 9], "cover_image_id": 2 } — every image of the package, in order. */
    public function reorder(Request $request, int $packageId): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($packageId);
        $existing = $package->images()->pluck('id')->sort()->values()->all();

        $data = $request->validate([
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['integer', 'distinct', Rule::in($existing)],
            'cover_image_id' => ['required', 'integer', Rule::in($existing)],
        ]);
        abort_unless(collect($data['image_ids'])->sort()->values()->all() === $existing, 422, 'image_ids must list every image of the package');

        DB::transaction(function () use ($data) {
            foreach ($data['image_ids'] as $position => $id) {
                PackageImage::query()->whereKey($id)->update(['sort_order' => $position + 1, 'is_cover' => $id === $data['cover_image_id']]);
            }
        });

        return $this->respond($request, $package, 'cms.package.images_reordered');
    }

    public function destroy(Request $request, int $packageId, int $imageId): JsonResponse
    {
        $package = TourPackage::query()->findOrFail($packageId);
        $image = $package->images()->findOrFail($imageId);

        // The last photo of a live package can't be removed: the card would have no image.
        if ($package->status === PackageStatus::Published && $package->images()->count() === 1) {
            throw new NotReadyToPublish([__('cms.publish_requirements.images')]);
        }

        DB::transaction(function () use ($package, $image) {
            $image->delete();
            if ($image->is_cover) {
                $package->images()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
            }
        });

        // The media file stays in the library; delete it there if it's no longer wanted.
        return $this->respond($request, $package, 'cms.package.image_removed');
    }

    private function respond(Request $request, TourPackage $package, string $action, int $status = 200): JsonResponse
    {
        $this->audit->record($action, $request->user('staff'), $package);
        RevalidateWebsite::dispatch(['packages']);

        $images = $package->images()->with('media')->get();

        return response()->json(['data' => $images->map(AdminContent::packageImage(...))], $status);
    }
}
