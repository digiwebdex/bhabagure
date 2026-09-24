<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\TourPhoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The group tour gallery on the home page: photos of travellers on their trips (docs/group-tour-gallery.md). */
class TourPhotoController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return TourPhoto::class;
    }

    protected function cacheTag(): string
    {
        return 'tour-photos';
    }

    protected function auditName(): string
    {
        return 'cms.tour_photo';
    }

    protected function present(Model $model): array
    {
        return AdminContent::tourPhoto($model->loadMissing(['image', 'tourPackage']));
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => TourPhoto::query()->with(['image', 'tourPackage'])->orderBy('sort_order')->orderBy('id')->get()->map(AdminContent::tourPhoto(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $photo = TourPhoto::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) TourPhoto::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $photo, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(TourPhoto::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $photo = TourPhoto::query()->findOrFail($id);
        $photo->update($this->validated($request));

        return $this->saved($request, $photo, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $photo = TourPhoto::query()->findOrFail($id);
        $photo->delete();

        return $this->saved($request, $photo, 'deleted');
    }

    /** The gallery shows the photo: there is nothing to show without one. */
    protected function publishProblems(Model $model): array
    {
        return $model->media_id === null ? [__('cms.publish_requirements.photo')] : [];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'caption_bn' => ['required', 'string', 'max:160'],
            'caption_en' => ['required', 'string', 'max:160'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
            // The month of the trip, as a month field sends it: "2026-09".
            'trip_month' => ['nullable', 'date_format:Y-m'],
            'tour_package_id' => ['nullable', 'integer', Rule::exists('tour_packages', 'id')->whereNull('deleted_at')],
        ]);
        if (array_key_exists('trip_month', $data)) {
            $data['trip_month'] = filled($data['trip_month']) ? "{$data['trip_month']}-01" : null;
        }

        return $data;
    }
}
