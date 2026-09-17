<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicContent;
use App\Models\Addon;
use App\Models\AirlinePartner;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\CreatorVideo;
use App\Models\Destination;
use App\Models\GalleryItem;
use App\Models\PackageDeparture;
use App\Models\PricingSlab;
use App\Models\Review;
use App\Models\SiteSetting;
use App\Models\TeamMember;
use App\Models\TourPackage;
use App\Models\VisaService;
use App\Support\CreatorProfile;
use App\Support\Money;
use App\Support\Payments\PaymentOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Read-only website content. Only published rows are ever returned: drafts (the unfinished Thai 02
 * package, a half-written post) and archived packages are invisible here, including by direct slug.
 * The website caches these responses per tag and refreshes them when the CMS saves.
 */
class PublicContentController extends Controller
{
    /** Booking statuses that hold seats on a departure. */
    private const SEAT_HOLDING_STATUSES = ['confirmed', 'completed'];

    public function destinations(): JsonResponse
    {
        $destinations = Destination::query()->orderBy('sort_order')->orderBy('id')->get();

        return $this->data($destinations->map(PublicContent::destination(...)));
    }

    public function packages(): JsonResponse
    {
        $packages = $this->publishedPackages()->orderBy('sort_order')->orderBy('id')->get();

        return $this->data($packages->map(PublicContent::package(...)));
    }

    public function package(string $slug): JsonResponse
    {
        $package = $this->publishedPackages()->where('slug', $slug)->firstOrFail();

        return $this->data(PublicContent::package($package));
    }

    public function departures(): JsonResponse
    {
        $departures = PackageDeparture::query()
            ->with('package')
            ->where('status', 'scheduled')
            ->whereDate('departs_on', '>=', now('Asia/Dhaka')->toDateString())
            ->whereHas('package', fn (Builder $query) => $query->published())
            ->withSum(['bookings as booked_pax' => fn (Builder $query) => $query->whereIn('status', self::SEAT_HOLDING_STATUSES)], 'pax_count')
            // Seats held while someone pays count as taken, as they do when the booking is created (DepartureSeats).
            ->withSum(['seatHolds as held_seats' => fn (Builder $query) => $query->active()], 'seats')
            ->orderBy('departs_on')
            ->get();

        return $this->data($departures->map(PublicContent::departure(...)));
    }

    public function posts(): JsonResponse
    {
        $categories = BlogCategory::query()->orderBy('sort_order')->orderBy('id')->get();
        $posts = BlogPost::query()->published()->with(['category', 'cover'])->latest('published_at')->get();

        return $this->data([
            'categories' => $categories->map(PublicContent::category(...)),
            'posts' => $posts->map(PublicContent::post(...)),
        ]);
    }

    public function post(string $slug): JsonResponse
    {
        $post = BlogPost::query()->published()->with(['category', 'cover'])->where('slug', $slug)->firstOrFail();

        return $this->data(PublicContent::post($post));
    }

    public function team(): JsonResponse
    {
        $team = TeamMember::query()->published()->with('photo')->orderBy('sort_order')->orderBy('id')->get();

        return $this->data($team->map(PublicContent::teamMember(...)));
    }

    public function reviews(): JsonResponse
    {
        $reviews = Review::query()->published()->orderBy('sort_order')->orderBy('id')->get();

        return $this->data($reviews->map(PublicContent::review(...)));
    }

    public function visas(): JsonResponse
    {
        return $this->data(VisaService::query()->published()->orderBy('sort_order')->orderBy('id')->get()->map(PublicContent::visaService(...)));
    }

    /** The airlines the agency books (docs/partners-and-payments.md); empty until one is published, and the band is hidden. */
    public function partners(): JsonResponse
    {
        $partners = AirlinePartner::query()->published()->with('logo')->orderBy('sort_order')->orderBy('id')->get();

        return $this->data($partners->map(PublicContent::airlinePartner(...)));
    }

    /** The travel host and the videos staff picked (docs/travel-host.md). No profile yet: null, and the section stays hidden. */
    public function creator(): JsonResponse
    {
        return $this->data([
            'profile' => PublicContent::creatorProfile(SiteSetting::get(CreatorProfile::KEY)),
            'videos' => CreatorVideo::query()->published()->orderBy('sort_order')->orderBy('id')->get()->map(PublicContent::creatorVideo(...))->values(),
        ]);
    }

    public function gallery(): JsonResponse
    {
        $items = GalleryItem::query()->published()->with('thumbnail')->orderBy('sort_order')->orderBy('id')->get();

        return $this->data($items->map(PublicContent::galleryItem(...)));
    }

    public function pricing(): JsonResponse
    {
        $settings = SiteSetting::get('pricing', []);

        return $this->data([
            'slabs' => PricingSlab::query()->orderBy('min_pax')->get()
                ->map(fn (PricingSlab $slab) => ['minPax' => $slab->min_pax, 'discountPercent' => Money::toNumber($slab->discount_percent)]),
            'singleRoomSupplementPercent' => $settings['singleRoomSupplementPercent'] ?? 0,
            'serviceChargePercent' => $settings['serviceChargePercent'] ?? 0,
            'maxTravellers' => $settings['maxTravellers'] ?? 20,
            'onlinePaymentChargePercent' => $settings['onlinePaymentChargePercent'] ?? 0,
            // Phase 8 §4.F: off, the booking form saves the booking and its page shows how to pay by hand.
            'onlineCheckout' => PaymentOptions::checkoutAvailable(),
            'addons' => Addon::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Addon $addon) => [
                    'code' => $addon->code,
                    'name' => $addon->localized('name'),
                    'price' => Money::toNumber($addon->price),
                    'unit' => $addon->unit,
                ]),
        ]);
    }

    public function settings(): JsonResponse
    {
        $settings = SiteSetting::query()->whereIn('key', SiteSettingKeys::PUBLIC)->pluck('value', 'key');

        return $this->data((object) $settings->all());
    }

    private function publishedPackages(): Builder
    {
        return TourPackage::query()->published()->with([
            'destination', 'itineraryDays', 'inclusions', 'tags', 'images.media',
        ]);
    }

    private function data(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
