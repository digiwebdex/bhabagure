<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Services\Media\ImageUploader;
use App\Services\Reviews\ReviewAlreadyDecided;
use App\Services\Reviews\ReviewSubmissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reviews shown on the website ("What travellers say"): written here by staff, or sent by customers from the website and
 * approved here first (docs/customer-reviews.md). Hidden on the website until at least one is published.
 */
class ReviewController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return Review::class;
    }

    protected function cacheTag(): string
    {
        return 'reviews';
    }

    protected function auditName(): string
    {
        return 'cms.review';
    }

    protected function present(Model $model): array
    {
        return AdminContent::review($model->loadMissing(['photos.image', 'booking']));
    }

    /** Staff reviews and approved customer ones; customers' waiting reviews have their own list, rejected ones none. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['draft', 'published'])]])['status'] ?? null;

        $reviews = Review::query()->listed()->with(['photos.image', 'booking'])->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $reviews->map(AdminContent::review(...))]);
    }

    /** Customers' reviews from the website waiting for staff, oldest first (docs/customer-reviews.md). */
    public function pending(): JsonResponse
    {
        $reviews = Review::query()->pending()->with(['photos.image', 'booking', 'package'])->orderBy('id')->get();

        // meta.total is what the sidebar badge counts (NavBadges `reviews`).
        return response()->json(['data' => $reviews->map(AdminContent::review(...)), 'meta' => ['total' => $reviews->count()]]);
    }

    public function approve(Request $request, int $id, ReviewSubmissions $submissions): JsonResponse
    {
        return $this->decided(fn () => $submissions->approve(Review::query()->findOrFail($id), $request->user('staff')));
    }

    public function reject(Request $request, int $id, ReviewSubmissions $submissions): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;

        return $this->decided(fn () => $submissions->reject(Review::query()->findOrFail($id), $reason, $request->user('staff')));
    }

    /** Show or hide one of a review's photos on the website. */
    public function photo(Request $request, int $photoId, ReviewSubmissions $submissions): JsonResponse
    {
        $shown = $request->validate(['is_shown' => ['required', 'boolean']])['is_shown'];
        $photo = $submissions->showPhoto(ReviewPhoto::query()->findOrFail($photoId), (bool) $shown, $request->user('staff'));

        return response()->json(['data' => AdminContent::review($photo->review->load(['photos.image', 'booking']))]);
    }

    /** @param callable(): Review $decide */
    private function decided(callable $decide): JsonResponse
    {
        try {
            $review = $decide();
        } catch (ReviewAlreadyDecided) {
            return response()->json(['message' => __('cms.review_decided'), 'code' => 'review_decided'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => AdminContent::review($review->load(['photos.image', 'booking']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $review = Review::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) Review::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $review, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => AdminContent::review(Review::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $review = Review::query()->findOrFail($id);
        $review->update($this->validated($request));

        return $this->saved($request, $review, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $review = Review::query()->with('photos.image')->findOrFail($id);
        // A customer's photos belong to their review: they go with it.
        $photos = $review->photos->pluck('image')->filter();
        $review->delete();
        foreach ($photos as $media) {
            app(ImageUploader::class)->delete($media);
        }

        return $this->saved($request, $review, 'deleted');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'quote_bn' => ['required', 'string', 'max:2000'],
            'quote_en' => ['nullable', 'string', 'max:2000'],
            'reviewer_name' => ['required', 'string', 'max:120'],
            'trip_label_bn' => ['nullable', 'string', 'max:160'],
            'trip_label_en' => ['nullable', 'string', 'max:160'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'travelled_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'tour_package_id' => ['nullable', 'integer', 'exists:tour_packages,id'],
        ]);
    }
}
