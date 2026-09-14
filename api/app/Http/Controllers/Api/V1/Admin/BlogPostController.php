<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ContentStatus;
use App\Exceptions\NotReadyToPublish;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\BlogPost;
use App\Services\AuditLogger;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Blog posts. Bodies are sanitised on save against the allow-list in App\Support\HtmlSanitizer.
 * A post is public only when published AND its published_at has arrived, so posts can be scheduled.
 */
class BlogPostController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ContentStatus::class)],
            'category' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $posts = BlogPost::query()->with(['category', 'cover'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, $slug) => $query->whereRelation('category', 'slug', $slug))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('title_en', 'like', "%{$search}%")->orWhere('title_bn', 'like', "%{$search}%")))
            ->orderByRaw('published_at IS NULL DESC')->latest('published_at')->latest('id')
            ->get();

        return response()->json(['data' => $posts->map(fn (BlogPost $post) => AdminContent::post($post, withBody: false))]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => AdminContent::post(BlogPost::query()->with(['category', 'cover'])->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $post = new BlogPost(['status' => ContentStatus::Draft]);
        $this->fill($post, $request);
        $post->forceFill(['created_by_staff_id' => $request->user('staff')->id, 'updated_by_staff_id' => $request->user('staff')->id])->save();

        return $this->respond($request, $post, 'created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = BlogPost::query()->findOrFail($id);
        $this->fill($post, $request);

        if ($post->status === ContentStatus::Published) {
            NotReadyToPublish::throwIfAny($this->publishProblems($post));
        }

        $post->forceFill(['updated_by_staff_id' => $request->user('staff')->id])->save();

        return $this->respond($request, $post, 'updated');
    }

    /** Optional body: { "published_at": "2026-10-01T09:00:00+06:00" } to schedule; defaults to now. */
    public function publish(Request $request, int $id): JsonResponse
    {
        $post = BlogPost::query()->findOrFail($id);
        $at = $request->validate(['published_at' => ['nullable', 'date']])['published_at'] ?? null;

        NotReadyToPublish::throwIfAny($this->publishProblems($post));

        $post->forceFill([
            'status' => ContentStatus::Published,
            'published_at' => $at ?? $post->published_at ?? now(),
            'updated_by_staff_id' => $request->user('staff')->id,
        ])->save();

        return $this->respond($request, $post, 'published');
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        $post = BlogPost::query()->findOrFail($id);
        $post->forceFill(['status' => ContentStatus::Draft, 'updated_by_staff_id' => $request->user('staff')->id])->save();

        return $this->respond($request, $post, 'unpublished');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $post = BlogPost::query()->findOrFail($id);
        $post->delete();
        $this->audit->record('cms.post.deleted', $request->user('staff'), $post);
        RevalidateWebsite::dispatch(['posts']);

        return response()->json(['data' => null]);
    }

    private function fill(BlogPost $post, Request $request): void
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('blog_posts', 'slug')->ignore($post->id)],
            'blog_category_id' => ['required', 'integer', 'exists:blog_categories,id'],
            'title_bn' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'excerpt_bn' => ['nullable', 'string', 'max:1000'],
            'excerpt_en' => ['nullable', 'string', 'max:1000'],
            'body_bn' => ['nullable', 'string', 'max:200000'],
            'body_en' => ['nullable', 'string', 'max:200000'],
            'author_bn' => ['nullable', 'string', 'max:120'],
            'author_en' => ['nullable', 'string', 'max:120'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'reading_minutes' => ['nullable', 'integer', 'between:1,120'],
            'seo_title_bn' => ['nullable', 'string', 'max:255'],
            'seo_title_en' => ['nullable', 'string', 'max:255'],
            'seo_description_bn' => ['nullable', 'string', 'max:500'],
            'seo_description_en' => ['nullable', 'string', 'max:500'],
        ]);

        $data['body_bn'] = HtmlSanitizer::clean($data['body_bn'] ?? null);
        $data['body_en'] = HtmlSanitizer::clean($data['body_en'] ?? null);

        // Computed from the longer body unless the editor typed a number.
        $override = ($data['reading_minutes'] ?? null) !== null;
        $data['reading_minutes'] = $override ? $data['reading_minutes'] : HtmlSanitizer::readingMinutes($data['body_bn'], $data['body_en']);
        $data['reading_minutes_override'] = $override;

        $post->fill($data);
    }

    /** @return list<string> */
    private function publishProblems(BlogPost $post): array
    {
        $problems = [];
        if (blank($post->body_bn) || blank($post->body_en)) {
            $problems[] = __('cms.publish_requirements.body');
        }
        if (blank($post->excerpt_bn) || blank($post->excerpt_en)) {
            $problems[] = __('cms.publish_requirements.excerpt');
        }

        return $problems;
    }

    private function respond(Request $request, BlogPost $post, string $action, int $status = 200): JsonResponse
    {
        $this->audit->record("cms.post.{$action}", $request->user('staff'), $post);
        RevalidateWebsite::dispatch(['posts']);

        return response()->json(['data' => AdminContent::post($post->fresh(['category', 'cover']))], $status);
    }
}
