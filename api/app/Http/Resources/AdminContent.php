<?php

namespace App\Http\Resources;

use App\Models\Addon;
use App\Models\AirlinePartner;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\CreatorVideo;
use App\Models\Destination;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Models\OfferBanner;
use App\Models\PackageDeparture;
use App\Models\PackageImage;
use App\Models\PackageInclusion;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\Tag;
use App\Models\TeamMember;
use App\Models\TourLandingDayPhoto;
use App\Models\TourLandingPage;
use App\Models\TourLandingVideo;
use App\Models\TourPackage;
use App\Models\TourPhoto;
use App\Models\VisaService;
use App\Support\CreatorProfile;
use App\Support\Money;

/**
 * CMS JSON. Field names match the request payloads and the database columns (snake_case), so an editor
 * can send back what it received. Money is a JSON number; dates are ISO strings.
 */
final class AdminContent
{
    public static function media(?Media $media): ?array
    {
        if ($media === null) {
            return null;
        }

        return [
            'id' => $media->id,
            'url' => $media->url(),
            'variants' => (object) $media->variantUrls(),
            'original_filename' => $media->original_filename,
            'mime' => $media->mime,
            'bytes' => $media->bytes,
            'width' => $media->width,
            'height' => $media->height,
            'alt_bn' => $media->alt_bn,
            'alt_en' => $media->alt_en,
            'credit' => $media->credit,
            'credit_url' => $media->credit_url,
            'is_placeholder' => $media->is_placeholder,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }

    public static function packageSummary(TourPackage $package): array
    {
        $cover = $package->relationLoaded('images') ? $package->images->first()?->media : null;

        return [
            'id' => $package->id,
            'code' => $package->code,
            'slug' => $package->slug,
            'title_bn' => $package->title_bn,
            'title_en' => $package->title_en,
            'destination' => $package->relationLoaded('destination') ? self::destination($package->destination) : null,
            'duration_days' => $package->duration_days,
            'duration_nights' => $package->duration_nights,
            'regular_price' => Money::toNumber($package->regular_price),
            'sale_price' => Money::toNumber($package->sale_price),
            // Hotel-category price grid (Phase 8 §4.D); null when the package is priced the old way.
            'price_grid' => $package->price_grid,
            'price_options' => $package->price_options,
            'trip_type' => $package->trip_type,
            'status' => $package->status->value,
            'published_at' => $package->published_at?->toIso8601String(),
            'is_featured' => $package->is_featured,
            'sort_order' => $package->sort_order,
            'missing_bangla' => $package->title_bn === null || $package->title_bn === $package->title_en,
            'cover' => self::media($cover),
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }

    /** Expects destination, itineraryDays, inclusions, tags and images.media loaded. */
    public static function package(TourPackage $package): array
    {
        $inclusion = fn (PackageInclusion $item) => ['text_bn' => $item->text_bn, 'text_en' => $item->text_en];

        return [
            ...self::packageSummary($package),
            'wp_trip_id' => $package->wp_trip_id,
            'destination_id' => $package->destination_id,
            'summary_bn' => $package->summary_bn,
            'summary_en' => $package->summary_en,
            'overview_bn' => $package->overview_bn,
            'overview_en' => $package->overview_en,
            'includes_airfare' => $package->includes_airfare,
            'group_mode' => $package->group_mode,
            'min_pax' => $package->min_pax,
            'departure_mode' => $package->departure_mode,
            'single_supplement_percent' => $package->single_supplement_percent,
            'twin_supplement_percent' => $package->twin_supplement_percent,
            'difficulty' => $package->difficulty,
            'seo_title_bn' => $package->seo_title_bn,
            'seo_title_en' => $package->seo_title_en,
            'seo_description_bn' => $package->seo_description_bn,
            'seo_description_en' => $package->seo_description_en,
            'itinerary' => $package->itineraryDays->map(fn ($day) => [
                'day_number' => $day->day_number,
                'title_bn' => $day->title_bn,
                'title_en' => $day->title_en,
                'body_bn' => $day->body_bn,
                'body_en' => $day->body_en,
            ])->values(),
            'includes' => $package->inclusions->where('kind', PackageInclusion::INCLUDE)->map($inclusion)->values(),
            'excludes' => $package->inclusions->where('kind', PackageInclusion::EXCLUDE)->map($inclusion)->values(),
            'activities' => $package->tags->where('type', Tag::ACTIVITY)->pluck('name_en')->values(),
            'trip_types' => $package->tags->where('type', Tag::TRIP_TYPE)->pluck('name_en')->values(),
            'images' => $package->images->map(self::packageImage(...))->values(),
            // Its landing page, for the editor's link (docs/tour-landing-pages.md).
            'landing_page' => $package->relationLoaded('landingPage') && $package->landingPage !== null
                ? ['id' => $package->landingPage->id, 'slug' => $package->landingPage->slug, 'status' => $package->landingPage->status->value]
                : null,
        ];
    }

    public static function packageImage(PackageImage $image): array
    {
        return [
            'id' => $image->id,
            'sort_order' => $image->sort_order,
            'is_cover' => $image->is_cover,
            'media' => self::media($image->media),
        ];
    }

    public static function departure(PackageDeparture $departure): array
    {
        return [
            'id' => $departure->id,
            'tour_package_id' => $departure->tour_package_id,
            'departs_on' => $departure->departs_on->toDateString(),
            'returns_on' => $departure->returns_on?->toDateString(),
            'seats_total' => $departure->seats_total,
            'seats_booked' => (int) ($departure->booked_pax ?? 0),
            // Its own price per person, null for the package's; the one the website's card shows (docs/departure-prices.md).
            'price' => Money::toNumber($departure->price),
            'is_featured' => $departure->is_featured,
            'is_guaranteed' => $departure->is_guaranteed,
            'status' => $departure->status,
            'group_leader_staff_id' => $departure->group_leader_staff_id,
            'notes' => $departure->notes,
        ];
    }

    public static function destination(Destination $destination): array
    {
        return $destination->only(['id', 'slug', 'name_bn', 'name_en', 'country_code', 'region', 'visa_on_arrival', 'sort_order']);
    }

    public static function category(BlogCategory $category): array
    {
        return $category->only(['id', 'slug', 'name_bn', 'name_en', 'tone', 'sort_order']);
    }

    public static function post(BlogPost $post, bool $withBody = true): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'blog_category_id' => $post->blog_category_id,
            'category' => $post->relationLoaded('category') ? self::category($post->category) : null,
            'title_bn' => $post->title_bn,
            'title_en' => $post->title_en,
            'excerpt_bn' => $post->excerpt_bn,
            'excerpt_en' => $post->excerpt_en,
            ...($withBody ? ['body_bn' => $post->body_bn, 'body_en' => $post->body_en] : []),
            'author_bn' => $post->author_bn,
            'author_en' => $post->author_en,
            'cover' => $post->relationLoaded('cover') ? self::media($post->cover) : null,
            'cover_media_id' => $post->cover_media_id,
            'reading_minutes' => $post->reading_minutes,
            'reading_minutes_override' => $post->reading_minutes_override,
            'seo_title_bn' => $post->seo_title_bn,
            'seo_title_en' => $post->seo_title_en,
            'seo_description_bn' => $post->seo_description_bn,
            'seo_description_en' => $post->seo_description_en,
            'status' => $post->status->value,
            'published_at' => $post->published_at?->toIso8601String(),
            // Published with a future date: saved as published, shown from published_at.
            'is_scheduled' => $post->isScheduled(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    public static function teamMember(TeamMember $member): array
    {
        return [
            ...$member->only(['id', 'name_bn', 'name_en', 'role_bn', 'role_en', 'employee_code', 'photo_media_id', 'staff_id', 'sort_order']),
            'status' => $member->status->value,
            'photo' => $member->relationLoaded('photo') ? self::media($member->photo) : null,
        ];
    }

    public static function review(Review $review): array
    {
        return [
            ...$review->only(['id', 'quote_bn', 'quote_en', 'reviewer_name', 'trip_label_bn', 'trip_label_en', 'rating', 'tour_package_id', 'sort_order']),
            'travelled_on' => $review->travelled_on?->toDateString(),
            'status' => $review->status->value,
            // docs/customer-reviews.md: who wrote it, and for a customer's review what staff check before approving.
            'source' => $review->source ?? Review::STAFF,
            'phone' => $review->phone,
            'pending' => $review->isPending(),
            'booking' => $review->relationLoaded('booking') && $review->booking ? ['id' => $review->booking->id, 'reference' => $review->booking->reference] : null,
            'photos' => $review->relationLoaded('photos') ? $review->photos->map(fn (ReviewPhoto $photo) => [
                'id' => $photo->id, 'is_shown' => $photo->is_shown, 'image' => self::media($photo->image),
            ])->values()->all() : [],
            'submitted_at' => $review->created_at?->toIso8601String(),
        ];
    }

    public static function galleryItem(GalleryItem $item): array
    {
        return [
            ...$item->only(['id', 'kind', 'url', 'caption_bn', 'caption_en', 'view_count', 'media_id', 'sort_order']),
            'status' => $item->status->value,
            'thumbnail' => $item->relationLoaded('thumbnail') ? self::media($item->thumbnail) : null,
        ];
    }

    /**
     * The travel host as the admin form edits it: flat fields, and each photo as the full media record so the form can
     * show it. Null profile: every field empty.
     */
    public static function creatorProfile(?array $value): array
    {
        $media = Media::query()->whereKey(CreatorProfile::mediaIds($value))->get()->keyBy('id');
        $picked = fn (?int $id) => $id !== null && $media->has($id) ? self::media($media->get($id)) : null;

        return [
            'name_bn' => $value['name']['bn'] ?? '',
            'name_en' => $value['name']['en'] ?? '',
            'bio_bn' => $value['bio']['bn'] ?? null,
            'bio_en' => $value['bio']['en'] ?? null,
            'facebook_url' => $value['facebook']['url'] ?? null,
            'facebook_followers' => $value['facebook']['followers'] ?? null,
            'facebook_photo_media_id' => $value['facebook']['photoMediaId'] ?? null,
            'facebook_photo' => $picked($value['facebook']['photoMediaId'] ?? null),
            'facebook_cover_media_id' => $value['facebook']['coverMediaId'] ?? null,
            'facebook_cover' => $picked($value['facebook']['coverMediaId'] ?? null),
            'youtube_url' => $value['youtube']['url'] ?? null,
            'youtube_subscribers' => $value['youtube']['subscribers'] ?? null,
            'youtube_video_count' => $value['youtube']['videoCount'] ?? null,
            'youtube_photo_media_id' => $value['youtube']['photoMediaId'] ?? null,
            'youtube_photo' => $picked($value['youtube']['photoMediaId'] ?? null),
            'youtube_cover_media_id' => $value['youtube']['coverMediaId'] ?? null,
            'youtube_cover' => $picked($value['youtube']['coverMediaId'] ?? null),
        ];
    }

    public static function offerBanner(OfferBanner $banner): array
    {
        return [
            ...$banner->only(['id', 'title_bn', 'title_en', 'media_id', 'link_url', 'sort_order']),
            'status' => $banner->status->value,
            'image' => $banner->relationLoaded('image') ? self::media($banner->image) : null,
        ];
    }

    public static function tourPhoto(TourPhoto $photo): array
    {
        return [
            ...$photo->only(['id', 'caption_bn', 'caption_en', 'media_id', 'tour_package_id', 'sort_order']),
            'trip_month' => $photo->tripMonthValue(),
            'status' => $photo->status->value,
            'image' => $photo->relationLoaded('image') ? self::media($photo->image) : null,
            // The linked tour's name, for the list.
            'package_title' => $photo->relationLoaded('tourPackage') ? $photo->tourPackage?->title_en : null,
        ];
    }

    /**
     * A tour landing page for its editor (docs/tour-landing-pages.md), with what it reads from the package — the days'
     * titles to put photos beside, the departures for the countdown, the cover the banner falls back to — shown, not
     * edited, here.
     */
    public static function tourLandingPage(TourLandingPage $page): array
    {
        $package = $page->tourPackage;

        return [
            ...self::tourLandingPageSummary($page),
            ...$page->only(['title_bn', 'title_en', 'intro_bn', 'intro_en', 'hero_media_id', 'countdown_departure_id']),
            'day_photos' => $page->dayPhotos->map(fn (TourLandingDayPhoto $photo) => [
                'day_number' => $photo->day_number,
                'media_id' => $photo->media_id,
                'image' => self::media($photo->media),
            ])->values(),
            // Reviews and videos (§9).
            'featured_review_ids' => $page->relationLoaded('featuredReviews') ? $page->featuredReviews->pluck('id')->values() : [],
            'videos' => $page->relationLoaded('videos') ? $page->videos->map(fn (TourLandingVideo $video) => [
                ...$video->only(['section', 'provider', 'url', 'youtube_id', 'title_en', 'title_bn', 'thumbnail_media_id']),
                'thumbnail' => self::media($video->thumbnail),
            ])->values() : [],
            // Every published review, to choose the ones put first: this tour's (shown on the page anyway) before the rest.
            'review_choices' => Review::query()->published()->with('package:id,title_en')
                ->when($package !== null, fn ($query) => $query->orderByRaw('tour_package_id <=> ? desc', [$package->id]))
                ->orderByDesc('travelled_on')->orderByDesc('id')->get(['id', 'tour_package_id', 'reviewer_name', 'rating', 'quote_en', 'quote_bn', 'travelled_on'])
                ->map(fn (Review $review) => [
                    'id' => $review->id,
                    'reviewer_name' => $review->reviewer_name,
                    'rating' => $review->rating,
                    'quote' => mb_strimwidth((string) ($review->quote_en ?: $review->quote_bn), 0, 140, '…'),
                    'travelled_on' => $review->travelled_on?->toDateString(),
                    'this_tour' => $package !== null && $review->tour_package_id === $package->id,
                    'package_title' => $review->package?->title_en,
                ])->values(),
            'package' => $package === null ? null : [
                'id' => $package->id,
                'slug' => $package->slug,
                'title_en' => $package->title_en,
                'title_bn' => $package->title_bn,
                'overview_en' => $package->overview_en,
                'overview_bn' => $package->overview_bn,
                'status' => $package->status->value,
                'cover' => self::media($package->images->first()?->media),
                'days' => $package->itineraryDays->map(fn ($day) => ['day_number' => $day->day_number, 'title_en' => $day->title_en, 'title_bn' => $day->title_bn])->values(),
                'departures' => $package->departures->sortBy('departs_on')->map(fn (PackageDeparture $departure) => [
                    'id' => $departure->id,
                    'departs_on' => $departure->departs_on->toDateString(),
                    'returns_on' => $departure->returns_on?->toDateString(),
                    'status' => $departure->status,
                ])->values(),
            ],
        ];
    }

    public static function tourLandingPageSummary(TourLandingPage $page): array
    {
        return [
            ...$page->only(['id', 'slug', 'tour_package_id', 'promote_on_home', 'sort_order']),
            'status' => $page->status->value,
            'hero' => $page->relationLoaded('hero') ? self::media($page->hero) : null,
            'package_title' => $page->tourPackage?->title_en,
            'package_slug' => $page->tourPackage?->slug,
            'package_status' => $page->tourPackage?->status->value,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    public static function airlinePartner(AirlinePartner $partner): array
    {
        return [
            ...$partner->only(['id', 'name_bn', 'name_en', 'media_id', 'website_url', 'sort_order']),
            'status' => $partner->status->value,
            'logo' => $partner->relationLoaded('logo') ? self::media($partner->logo) : null,
        ];
    }

    public static function creatorVideo(CreatorVideo $video): array
    {
        return [
            ...$video->only(['id', 'youtube_id', 'title_bn', 'title_en', 'sort_order']),
            'url' => $video->watchUrl(),
            'thumbnail_url' => $video->thumbnailUrl(),
            'status' => $video->status->value,
        ];
    }

    public static function visaService(VisaService $visa): array
    {
        return [
            ...$visa->only(['id', 'slug', 'country_code', 'country_bn', 'country_en', 'visa_type_bn', 'visa_type_en', 'processing_bn', 'processing_en',
                'stay_bn', 'stay_en', 'requirements_bn', 'requirements_en', 'notes_bn', 'notes_en', 'sort_order']),
            'price' => $visa->price === null ? null : Money::toNumber($visa->price),
            'status' => $visa->status->value,
        ];
    }

    public static function addon(Addon $addon): array
    {
        return [
            ...$addon->only(['id', 'code', 'name_bn', 'name_en', 'unit', 'is_active', 'sort_order']),
            'price' => Money::toNumber($addon->price),
        ];
    }
}
