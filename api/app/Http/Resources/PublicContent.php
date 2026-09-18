<?php

namespace App\Http\Resources;

use App\Enums\MediaVariant;
use App\Models\AirlinePartner;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\CreatorVideo;
use App\Models\Destination;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Models\OfferBanner;
use App\Models\PackageDeparture;
use App\Models\PackageInclusion;
use App\Models\Review;
use App\Models\Tag;
use App\Models\TeamMember;
use App\Models\TourPackage;
use App\Models\VisaService;
use App\Support\CreatorProfile;
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
            // {"3": {"1": 95000, "2": 75000, …}, …} or null: web/src/lib/content/types.ts TourPackage.priceGrid.
            'priceGrid' => $package->price_grid,
            // Extras and estimates beside the package price (docs/package-price-options.md); each language falls back
            // to the other.
            'priceOptions' => array_map(fn (array $option) => [
                'label' => ['bn' => ($option['label_bn'] ?? null) ?: $option['label_en'], 'en' => $option['label_en']],
                'extraPerPerson' => isset($option['extra_per_person']) ? Money::toNumber($option['extra_per_person']) : null,
                'estimate' => ($option['estimate_en'] ?? null) === null && ($option['estimate_bn'] ?? null) === null
                    ? null
                    : ['bn' => ($option['estimate_bn'] ?? null) ?: $option['estimate_en'], 'en' => ($option['estimate_en'] ?? null) ?: $option['estimate_bn']],
            ], $package->price_options ?? []),
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

    /**
     * The travel host for the home page (docs/travel-host.md; web/src/lib/content/types.ts Creator). Null until a profile
     * with at least one link is saved, which keeps the section hidden. Each language falls back to the other.
     */
    public static function creatorProfile(?array $value): ?array
    {
        if (! CreatorProfile::isShown($value)) {
            return null;
        }
        $media = Media::query()->whereKey(CreatorProfile::mediaIds($value))->get()->keyBy('id');
        $image = fn (?int $id, MediaVariant $variant) => $id === null ? null : self::image($media->get($id), $variant);
        $pair = fn (?array $text) => $text === null || (blank($text['bn'] ?? null) && blank($text['en'] ?? null))
            ? null
            : ['bn' => ($text['bn'] ?? null) ?: $text['en'], 'en' => ($text['en'] ?? null) ?: $text['bn']];
        $facebook = $value['facebook'] ?? null;
        $youtube = $value['youtube'] ?? null;

        return [
            'name' => $pair($value['name']),
            'bio' => $pair($value['bio'] ?? null),
            // The photo is a small round avatar; the cover spans the card, so it gets the larger size.
            'facebook' => $facebook === null ? null : [
                'url' => $facebook['url'],
                'followers' => $facebook['followers'] ?? null,
                'photo' => $image($facebook['photoMediaId'] ?? null, MediaVariant::Thumb),
                'cover' => $image($facebook['coverMediaId'] ?? null, MediaVariant::Detail),
            ],
            'youtube' => $youtube === null ? null : [
                'url' => $youtube['url'],
                'subscribers' => $youtube['subscribers'] ?? null,
                'videoCount' => $youtube['videoCount'] ?? null,
                'photo' => $image($youtube['photoMediaId'] ?? null, MediaVariant::Thumb),
                'cover' => $image($youtube['coverMediaId'] ?? null, MediaVariant::Detail),
            ],
        ];
    }

    /** An offer banner for the slideshow under the hero (docs/offer-banners.md). Wide, so it comes at the full size. */
    public static function offerBanner(OfferBanner $banner): array
    {
        return [
            'title' => $banner->localized('title'),
            'image' => self::image($banner->image, MediaVariant::Full),
            'linkUrl' => $banner->link_url,
        ];
    }

    /** An airline the agency books: its name, its logo and, when it has one, a link to it. */
    public static function airlinePartner(AirlinePartner $partner): array
    {
        return [
            'name' => $partner->localized('name'),
            'logo' => self::image($partner->logo, MediaVariant::Thumb),
            'websiteUrl' => $partner->website_url,
        ];
    }

    public static function creatorVideo(CreatorVideo $video): array
    {
        return [
            'youtubeId' => $video->youtube_id,
            'url' => $video->watchUrl(),
            'title' => $video->localized('title'),
            'thumbnail' => $video->thumbnailUrl(),
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

    /** A published visa service; requirements as lists, one item per line. web/src/lib/content/types.ts VisaService. */
    public static function visaService(VisaService $visa): array
    {
        return [
            'slug' => $visa->slug,
            'countryCode' => $visa->country_code,
            'country' => $visa->localized('country'),
            'visaType' => $visa->localized('visa_type'),
            'price' => $visa->price === null ? null : Money::toNumber($visa->price),
            'processing' => $visa->localizedOrNull('processing'),
            'stay' => $visa->localizedOrNull('stay'),
            'requirements' => $visa->requirementLists(),
            'notes' => $visa->localizedOrNull('notes'),
            'updatedAt' => $visa->updated_at?->toIso8601String(),
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
