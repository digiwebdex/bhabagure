<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BlogCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $categories = BlogCategory::query()->withCount('posts')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $categories->map(fn (BlogCategory $category) => [
            ...AdminContent::category($category),
            'posts_count' => $category->posts_count,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $category = BlogCategory::query()->create([
            ...$this->validated($request),
            'sort_order' => (int) BlogCategory::query()->max('sort_order') + 1,
        ]);

        return $this->respond($request, $category, 'created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = BlogCategory::query()->findOrFail($id);
        $category->update($this->validated($request, $category));

        return $this->respond($request, $category, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = BlogCategory::query()->findOrFail($id);

        if (BlogPost::withTrashed()->where('blog_category_id', $category->id)->exists()) {
            return response()->json(['message' => __('cms.category_in_use'), 'code' => 'category_in_use'], 409);
        }

        $category->delete();
        $this->audit->record('cms.blog_category.deleted', $request->user('staff'), $category);
        RevalidateWebsite::dispatch(['posts']);

        return response()->json(['data' => null]);
    }

    private function validated(Request $request, ?BlogCategory $category = null): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('blog_categories', 'slug')->ignore($category?->id)],
            'name_bn' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'tone' => ['required', Rule::in(BlogCategory::TONES)],
        ]);
    }

    private function respond(Request $request, BlogCategory $category, string $action, int $status = 200): JsonResponse
    {
        $this->audit->record("cms.blog_category.{$action}", $request->user('staff'), $category);
        RevalidateWebsite::dispatch(['posts']);

        return response()->json(['data' => AdminContent::category($category)], $status);
    }
}
