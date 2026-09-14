<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\GalleryItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Facebook reels and photos linked from the website gallery, each with an uploaded thumbnail. */
class GalleryItemController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return GalleryItem::class;
    }

    protected function cacheTag(): string
    {
        return 'gallery';
    }

    protected function auditName(): string
    {
        return 'cms.gallery_item';
    }

    protected function present(Model $model): array
    {
        return AdminContent::galleryItem($model->loadMissing('thumbnail'));
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['draft', 'published'])]])['status'] ?? null;

        $items = GalleryItem::query()->with('thumbnail')->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $items->map(AdminContent::galleryItem(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = GalleryItem::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) GalleryItem::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $item, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(GalleryItem::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = GalleryItem::query()->findOrFail($id);
        $item->update($this->validated($request));

        return $this->saved($request, $item, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = GalleryItem::query()->findOrFail($id);
        $item->delete();

        return $this->saved($request, $item, 'deleted');
    }

    protected function publishProblems(Model $model): array
    {
        return $model->media_id === null ? [__('cms.publish_requirements.images')] : [];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(GalleryItem::KINDS)],
            'url' => ['required', 'url:https', 'max:500', 'regex:#^https://(www\.|m\.)?facebook\.com/#'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
            'caption_bn' => ['nullable', 'string', 'max:255'],
            'caption_en' => ['nullable', 'string', 'max:255'],
            'view_count' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
