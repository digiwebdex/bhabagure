<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\Review;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer reviews shown on the website. Hidden there until at least one is published. */
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
        return AdminContent::review($model);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['draft', 'published'])]])['status'] ?? null;

        $reviews = Review::query()->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $reviews->map(AdminContent::review(...))]);
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
        $review = Review::query()->findOrFail($id);
        $review->delete();

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
