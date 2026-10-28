<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PackageStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\PackageDeparture;
use App\Models\TourLandingPage;
use App\Models\TourLandingVideo;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use App\Support\LandingPreviewToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Website → Tour landing pages (docs/tour-landing-pages.md): a group tour's own page, with its banner, the photos beside
 * each day, the countdown and the home page promotion. The tour's content is the package's and is edited there.
 */
class TourLandingPageController extends Controller
{
    use ManagesPublishedList;

    private const RELATIONS = ['hero', 'dayPhotos.media', 'tourPackage.itineraryDays', 'tourPackage.departures', 'tourPackage.images.media', 'featuredReviews', 'videos.thumbnail'];

    /** Lists sent with the page and saved apart from its own fields. */
    private const LISTS = ['day_photos', 'featured_review_ids', 'videos'];

    protected function modelClass(): string
    {
        return TourLandingPage::class;
    }

    protected function cacheTag(): string
    {
        return 'tour-pages';
    }

    protected function auditName(): string
    {
        return 'cms.tour_landing_page';
    }

    protected function present(Model $model): array
    {
        return AdminContent::tourLandingPage($model->load(self::RELATIONS));
    }

    public function index(): JsonResponse
    {
        $pages = TourLandingPage::query()->with(['tourPackage:id,title_en,slug,status', 'hero'])->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $pages->map(AdminContent::tourLandingPageSummary(...))]);
    }

    /** The group tours a landing page can be made for, and which already have one. */
    public function options(): JsonResponse
    {
        $taken = TourLandingPage::query()->pluck('id', 'tour_package_id');
        $packages = TourPackage::query()->where('trip_type', TourPackage::GROUP_FIXED)->whereIn('status', [PackageStatus::Draft, PackageStatus::Published])
            ->orderBy('title_en')->get(['id', 'title_en', 'slug', 'status']);

        return response()->json(['data' => $packages->map(fn (TourPackage $package) => [
            'id' => $package->id,
            'title_en' => $package->title_en,
            'slug' => $package->slug,
            'status' => $package->status->value,
            'landing_page_id' => $taken[$package->id] ?? null,
        ])->values()]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(TourLandingPage::query()->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $page = DB::transaction(function () use ($request, $data) {
            $page = TourLandingPage::query()->create([
                ...collect($data)->except(self::LISTS)->all(),
                'sort_order' => (int) TourLandingPage::query()->max('sort_order') + 1,
            ]);
            $page->forceFill(['status' => 'draft', 'created_by_staff_id' => $request->user('staff')->id, 'updated_by_staff_id' => $request->user('staff')->id])->save();
            $this->saveLists($page, $data);

            return $page;
        });

        return $this->saved($request, $page, 'created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = TourLandingPage::query()->findOrFail($id);
        $data = $this->validated($request, $page);
        DB::transaction(function () use ($request, $page, $data) {
            $page->fill(collect($data)->except(self::LISTS)->all());
            $page->forceFill(['updated_by_staff_id' => $request->user('staff')->id])->save();
            if (array_intersect(self::LISTS, array_keys($data)) !== []) {
                $this->saveLists($page, $data);
                $page->touch();
            }
        });

        return $this->saved($request, $page, 'updated');
    }

    /**
     * A link to see the page as saved, draft or not, for 30 minutes (docs/tour-landing-pages.md §6). The token is in the
     * link's #hash, which browsers never send to a server; it is not kept here or in the audit log.
     */
    public function previewLink(Request $request, int $id): JsonResponse
    {
        $page = TourLandingPage::query()->findOrFail($id);
        ['token' => $token, 'expiresAt' => $expiresAt] = LandingPreviewToken::issue($page);
        app(AuditLogger::class)->record('cms.tour_landing_page.preview_link', $request->user('staff'), $page, ['expires_at' => $expiresAt->toIso8601String()]);
        $site = rtrim((string) config('bhabaghure.web_url'), '/');

        return response()->json(['data' => [
            'url_bn' => "{$site}/tours/preview#{$token}",
            'url_en' => "{$site}/en/tours/preview#{$token}",
            'expires_at' => $expiresAt->toIso8601String(),
        ]], 201)->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $page = TourLandingPage::query()->findOrFail($id);
        $page->delete();

        return $this->saved($request, $page, 'deleted');
    }

    /** Live only with a published group tour behind it: its prices, dates and days are what the page shows. */
    protected function publishProblems(Model $model): array
    {
        /** @var TourLandingPage $model */
        $package = $model->tourPackage;
        $problems = [];
        if ($package === null || $package->status !== PackageStatus::Published) {
            $problems[] = __('cms.landing_page.package_not_published');
        }
        if ($package !== null && ! $package->isGroupTour()) {
            $problems[] = __('cms.landing_page.not_group_tour');
        }

        return $problems;
    }

    /** The day photos, featured reviews and videos sent, each list replaced as a whole; untouched when not sent. */
    private function saveLists(TourLandingPage $page, array $data): void
    {
        if (array_key_exists('day_photos', $data)) {
            $this->saveDayPhotos($page, $data['day_photos'] ?? []);
        }
        if (array_key_exists('featured_review_ids', $data)) {
            $page->featuredReviews()->sync(collect($data['featured_review_ids'] ?? [])->values()->mapWithKeys(fn ($id, $i) => [(int) $id => ['sort_order' => $i + 1]])->all());
        }
        if (array_key_exists('videos', $data)) {
            $page->videos()->delete();
            $position = [];
            foreach ($data['videos'] ?? [] as $video) {
                $known = TourLandingVideo::recognise(trim($video['url']));
                $position[$video['section']] = ($position[$video['section']] ?? 0) + 1;
                $page->videos()->create([
                    'section' => $video['section'],
                    ...$known,
                    'url' => trim($video['url']),
                    'title_en' => filled($video['title_en'] ?? null) ? trim($video['title_en']) : null,
                    'title_bn' => filled($video['title_bn'] ?? null) ? trim($video['title_bn']) : null,
                    'thumbnail_media_id' => $video['thumbnail_media_id'] ?? null,
                    'sort_order' => $position[$video['section']],
                ]);
            }
        }
    }

    /** @param list<array{day_number: int, media_id: int}> $photos */
    private function saveDayPhotos(TourLandingPage $page, array $photos): void
    {
        $page->dayPhotos()->delete();
        $position = [];
        foreach ($photos as $photo) {
            $day = (int) $photo['day_number'];
            $position[$day] = ($position[$day] ?? 0) + 1;
            $page->dayPhotos()->create(['day_number' => $day, 'media_id' => (int) $photo['media_id'], 'sort_order' => $position[$day]]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?TourLandingPage $page): array
    {
        foreach (['title_bn', 'title_en', 'intro_bn', 'intro_en'] as $field) {
            if ($request->has($field) && trim((string) $request->input($field)) === '') {
                $request->merge([$field => null]);
            }
        }
        $packageId = (int) $request->input('tour_package_id');

        $data = $request->validate([
            // "preview" is the website's staff preview (/tours/preview), not a page's address.
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(['preview']), Rule::unique('tour_landing_pages', 'slug')->ignore($page?->id)],
            'tour_package_id' => [
                'required', 'integer', Rule::exists('tour_packages', 'id')->whereNull('deleted_at'), Rule::unique('tour_landing_pages', 'tour_package_id')->ignore($page?->id),
                // A landing page is for a group tour: its departures give the countdown and Book Now its date.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (TourPackage::query()->whereKey($value)->value('trip_type') !== TourPackage::GROUP_FIXED) {
                        $fail(__('cms.landing_page.not_group_tour'));
                    }
                },
            ],
            'title_bn' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'intro_bn' => ['nullable', 'string', 'max:10000'],
            'intro_en' => ['nullable', 'string', 'max:10000'],
            'hero_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'countdown_departure_id' => [
                'nullable', 'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($packageId) {
                    if (! PackageDeparture::query()->whereKey($value)->where('tour_package_id', $packageId)->exists()) {
                        $fail(__('cms.landing_page.departure_not_of_package'));
                    }
                },
            ],
            'promote_on_home' => ['sometimes', 'boolean'],
            'day_photos' => ['sometimes', 'array', 'max:180'],
            'day_photos.*.day_number' => ['required', 'integer', 'between:1,60'],
            'day_photos.*.media_id' => ['required', 'integer', 'exists:media,id'],
            // Reviews put first (docs/tour-landing-pages.md §9): published ones only.
            'featured_review_ids' => ['sometimes', 'array', 'max:12'],
            'featured_review_ids.*' => ['integer', 'distinct', Rule::exists('reviews', 'id')->where('status', 'published')],
            // Guests' video feedback and travel vlogs: YouTube or Facebook links.
            'videos' => ['sometimes', 'array', 'max:24'],
            'videos.*.section' => ['required', Rule::in(TourLandingVideo::SECTIONS)],
            'videos.*.url' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || TourLandingVideo::recognise($value) === null) {
                    $fail(__('cms.landing_page.video_url'));
                }
            }],
            'videos.*.title_en' => ['nullable', 'string', 'max:160'],
            'videos.*.title_bn' => ['nullable', 'string', 'max:160'],
            'videos.*.thumbnail_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ]);

        // Up to twelve videos in each section.
        $perSection = collect($data['videos'] ?? [])->countBy('section');
        if ($perSection->max() > 12) {
            throw ValidationException::withMessages(['videos' => [__('cms.landing_page.too_many_videos')]]);
        }

        // Up to three photos beside a day.
        $perDay = collect($data['day_photos'] ?? [])->countBy(fn (array $photo) => (int) $photo['day_number']);
        if ($perDay->max() > 3) {
            throw ValidationException::withMessages(['day_photos' => [__('cms.landing_page.too_many_day_photos')]]);
        }

        return $data;
    }
}
