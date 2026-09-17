<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\OfferBanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The offer banners that slide under the hero video on the home page (docs/offer-banners.md). */
class OfferBannerController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return OfferBanner::class;
    }

    protected function cacheTag(): string
    {
        return 'offers';
    }

    protected function auditName(): string
    {
        return 'cms.offer_banner';
    }

    protected function present(Model $model): array
    {
        return AdminContent::offerBanner($model->loadMissing('image'));
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => OfferBanner::query()->with('image')->orderBy('sort_order')->orderBy('id')->get()->map(AdminContent::offerBanner(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $banner = OfferBanner::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) OfferBanner::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $banner, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(OfferBanner::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $banner = OfferBanner::query()->findOrFail($id);
        $banner->update($this->validated($request));

        return $this->saved($request, $banner, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $banner = OfferBanner::query()->findOrFail($id);
        $banner->delete();

        return $this->saved($request, $banner, 'deleted');
    }

    /** A banner is its picture: there is nothing to slide without one. */
    protected function publishProblems(Model $model): array
    {
        return $model->media_id === null ? [__('cms.publish_requirements.banner')] : [];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title_bn' => ['required', 'string', 'max:160'],
            'title_en' => ['required', 'string', 'max:160'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
            // Somewhere on this site, or anywhere else worth sending a visitor.
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(https://|/)[^\s]*$#'],
        ], ['link_url.regex' => __('cms.banner_link')]);
    }
}
