<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\CreatorVideo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The YouTube videos under the travel host's cards (docs/travel-host.md), picked by staff so a sponsored upload does not
 * appear on the agency's home page unless someone chose it. Paste the link; the thumbnail comes from YouTube.
 */
class CreatorVideoController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return CreatorVideo::class;
    }

    protected function cacheTag(): string
    {
        return 'creator';
    }

    protected function auditName(): string
    {
        return 'cms.creator_video';
    }

    protected function present(Model $model): array
    {
        return AdminContent::creatorVideo($model);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => CreatorVideo::query()->orderBy('sort_order')->orderBy('id')->get()->map(AdminContent::creatorVideo(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $video = CreatorVideo::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) CreatorVideo::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $video, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(CreatorVideo::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $video = CreatorVideo::query()->findOrFail($id);
        $video->update($this->validated($request, $video));

        return $this->saved($request, $video, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $video = CreatorVideo::query()->findOrFail($id);
        $video->delete();

        return $this->saved($request, $video, 'deleted');
    }

    /** @return array{youtube_id: string, title_bn: ?string, title_en: ?string} */
    private function validated(Request $request, ?CreatorVideo $video = null): array
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'regex:'.CreatorVideo::URL],
            'title_bn' => ['nullable', 'string', 'max:200', 'required_without:title_en'],
            'title_en' => ['nullable', 'string', 'max:200', 'required_without:title_bn'],
        ], [
            'url.regex' => __('cms.video_url'),
            'title_bn.required_without' => __('cms.video_title'),
            'title_en.required_without' => __('cms.video_title'),
        ]);

        $youtubeId = CreatorVideo::idFromUrl($data['url']);
        if (CreatorVideo::query()->where('youtube_id', $youtubeId)->when($video, fn ($query) => $query->whereKeyNot($video->id))->exists()) {
            throw ValidationException::withMessages(['url' => __('cms.video_exists')]);
        }

        return [
            'youtube_id' => $youtubeId,
            'title_bn' => filled($data['title_bn'] ?? null) ? trim($data['title_bn']) : null,
            'title_en' => filled($data['title_en'] ?? null) ? trim($data['title_en']) : null,
        ];
    }
}
