<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\BlogPost;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Models\PackageImage;
use App\Models\TeamMember;
use App\Services\AuditLogger;
use App\Services\Media\ImageUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

/** The image library behind every CMS photo field. */
class MediaController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        $page = Media::query()
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('original_filename', 'like', "%{$search}%")->orWhere('alt_en', 'like', "%{$search}%")->orWhere('alt_bn', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(AdminContent::media(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** multipart/form-data: file (≤ 5 MB, JPEG/PNG/WebP), optional alt_bn, alt_en, credit, credit_url. */
    public function store(Request $request, ImageUploader $uploader): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp'])->max((int) config('bhabaghure.media.max_upload_kb'))],
            ...$this->metadataRules(),
        ]);

        $media = $uploader->store($request->file('file'), $request->user('staff'), $data);
        $this->audit->record('cms.media.uploaded', $request->user('staff'), $media);

        return response()->json(['data' => AdminContent::media($media)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $media = Media::query()->findOrFail($id);
        $media->update($request->validate($this->metadataRules()));
        $this->audit->record('cms.media.updated', $request->user('staff'), $media);

        return response()->json(['data' => AdminContent::media($media)]);
    }

    public function destroy(Request $request, int $id, ImageUploader $uploader): JsonResponse
    {
        $media = Media::query()->findOrFail($id);

        $inUse = PackageImage::query()->where('media_id', $media->id)->exists()
            || BlogPost::withTrashed()->where('cover_media_id', $media->id)->exists()
            || TeamMember::query()->where('photo_media_id', $media->id)->exists()
            || GalleryItem::query()->where('media_id', $media->id)->exists();

        if ($inUse) {
            return response()->json(['message' => __('media.in_use'), 'code' => 'media_in_use'], 409);
        }

        $uploader->delete($media);
        $this->audit->record('cms.media.deleted', $request->user('staff'), $media);

        return response()->json(['data' => null]);
    }

    private function metadataRules(): array
    {
        return [
            'alt_bn' => ['nullable', 'string', 'max:255'],
            'alt_en' => ['nullable', 'string', 'max:255'],
            'credit' => ['nullable', 'string', 'max:160'],
            'credit_url' => ['nullable', 'url:https,http', 'max:500'],
        ];
    }
}
