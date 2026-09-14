<?php

namespace App\Http\Resources;

use App\Enums\MediaVariant;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Destination;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Models\PackageDeparture;
use App\Models\PackageInclusion;
use App\Models\Review;
use App\Models\Tag;
use App\Models\TeamMember;
use App\Models\TourPackage;
use App\Support\Money;

/**
 * The public content API's JSON, shaped exactly like the website's ContentBundle types
 * (web/src/lib/content/types.ts). That contract is camelCase with { bn, en } pairs; the staff/CMS API
 * uses the database's snake_case column names instead. tests/Feature/PublicContentContractTest keeps
 * this in step with packages/content-seed.
 */
final class PublicContent
{
    public static function destination(Destination $destination): array
    {
        return [
            'slug' => $destination->slug,
            'name' => $destination->localized('name'),
            'countryCode' => $destination->country_code,
            'region' => $destination->region,
            // Only where it's true, as the content seed lists it.
            ...($destination->visa_on_arrival ? ['visaOnArrival' => true] : []),
        ];
    }

    /** Expects destination, itineraryDays, inclusions, tags and images.media loaded. */
    public static function package(TourPackage $package): array
    {
        $inclusions = $package->inclusions;

        return [
            'code' => $package->code,
            'slug' => $package->slug,
            'wpTripId' => $package->wp_trip_id,
            'destination' => $package->destination->slug,
            'status' => $package->status->value,
            'title' => $package->localized('title'),
            'summary' => $package->localized('summary'),
            'durationDays' => $package->duration_days,
            'durationNights' => $package->duration_nights,
            'regularPrice' => Money::toNumber($package->regular_price),
            'salePrice' => Money::toNumber($package->sale_price),
            'includesAirfare' => $package->includes_airfare,
            'groupMode' => $package->group_mode,
            'minPax' => $package->min_pax,
            'departureMode' => $package->departure_mode,
            'itinerary' => $package->itineraryDays->map(fn ($day) => [
                'day' => $day->day_number,
                'title' => $day->localized('title'),
                'body' => $day->localized('body'),
            ])->values()->all(),
            'includes' => $inclusions->where('kind', PackageInclusion::INCLUDE)->map(fn ($item) => $item->localized('text'))->values()->all(),
            'excludes' => $inclusions->where('kind', PackageInclusion::EXCLUDE)->map(fn ($item) => $item->localized('text'))->values()->all(),
            'activities' => $package->tags->where('type', Tag::ACTIVITY)->pluck('name_en')->values()->all(),
            'tripTypes' => $package->tags->where('type', Tag::TRIP_TYPE)->pluck('name_en')->values()->all(),
            'images' => $package->images->map(fn ($image) => self::image($image->media))->values()->all(),
            'seo' => [
                'title' => $package->localizedOrNull('seo_title'),
                'description' => $package->localizedOrNull('seo_description'),
            ],
        ];
    }

    public static function image(?Media $media, MediaVariant $variant = MediaVariant::Detail): ?array
    {
        if ($media === null) {
            return null;
        }

        return [
            'url' => $media->url($variant),
            'alt' => $media->localized('alt'),
            'credit' => $media->credit,
            'creditUrl' => $media->credit_url,
            'isPlaceholder' => $media->is_placeholder,
            'width' => $media->width,
            'height' => $media->height,
            // WebP sizes for srcset: thumb 400, card 800, detail 1600, full 2400 px wide at most.
            'variants' => (object) $media->variantUrls(),
        ];
    }

    /** Expects package loaded and booked_pax selected (see PublicContentController::departures). */
    public static function departure(PackageDeparture $departure): array
    {
        return [
            'packageCode' => $departure->package->code,
            'dateLabel' => null,
            'departsOn' => $departure->departs_on->toDateString(),
            'returnsOn' => $departure->returns_on?->toDateString(),
            'seatsTotal' => $departure->seats_total,
            'seatsBooked' => min($departure->seats_total, (int) $departure->booked_pax + (int) $departure->held_seats),
            'isGuaranteed' => $departure->is_guaranteed,
        ];
    }

    public static function category(BlogCategory $category): array
    {
        return ['slug' => $category->slug, 'name' => $category->localized('name'), 'tone' => $category->tone];
    }

    /** Expects category and cover loaded. */
    public static function post(BlogPost $post): array
    {
        return [
            'slug' => $post->slug,
            'category' => $post->category->slug,
            'publishedAt' => $post->published_at?->timezone('Asia/Dhaka')->toDateString(),
            'title' => $post->localized('title'),
            'excerpt' => $post->localized('excerpt'),
            'body' => $post->localized('body'),
            'author' => $post->localized('author'),
            'readingMinutes' => $post->reading_minutes,
            'status' => $post->status->value,
            'cover' => self::image($post->cover, MediaVariant::Card),
            'seo' => [
                'title' => $post->localizedOrNull('seo_title'),
                'description' => $post->localizedOrNull('seo_description'),
            ],
        ];
    }

    public static function teamMember(TeamMember $member): array
    {
        return [
            'employeeCode' => $member->employee_code,
            'name' => $member->localized('name'),
            'role' => $member->localized('role'),
            'photo' => self::image($member->photo, MediaVariant::Card),
            'sortOrder' => $member->sort_order,
            'isVisible' => $member->isPublished(),
        ];
    }

    public static function review(Review $review): array
    {
        return [
            'quote' => $review->localized('quote'),
            'reviewerName' => $review->reviewer_name,
            'tripLabel' => $review->localized('trip_label'),
            'rating' => $review->rating,
        ];
    }

    public static function galleryItem(GalleryItem $item): array
    {
        return [
            'kind' => $item->kind,
            'url' => $item->url,
            'viewsThousands' => $item->view_count === null ? null : (int) round($item->view_count / 1000),
            'caption' => $item->localizedOrNull('caption'),
            'thumbnail' => self::image($item->thumbnail, MediaVariant::Card),
        ];
    }
}
