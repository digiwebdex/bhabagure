<?php

namespace App\Services\Brochures;

use App\Enums\ContentStatus;
use App\Enums\MediaVariant;
use App\Enums\PackageStatus;
use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Models\Media;
use App\Models\SiteSetting;
use App\Models\TourLandingDayPhoto;
use App\Models\TourLandingPage;
use App\Models\TourPackage;
use App\Models\TourPhoto;
use App\Support\Brand;
use App\Support\LandingPreviewToken;
use App\Support\Pricing\PricingConfig;
use App\Support\Release;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A group tour's brochure as its landing page, printed (docs/tour-landing-pages.md §7): the same page, words and photos
 * as on the website, from the website's own print view. Only with BROCHURE_LANDING_PDF on; then a package with a
 * published landing page gets it, and every other package — or this one, should anything fail — the photo brochure.
 * A PDF is stored only when it is complete, and made again whenever anything printed in it changes.
 */
class LandingBrochurePdf
{
    /** Bump when the print view changes in a way the release doesn't cover. */
    public const VERSION = 1;

    public function __construct(private readonly PageRenderer $renderer) {}

    public static function enabled(): bool
    {
        return (bool) config('bhabaghure.brochures.landing_pdf') && filled(config('bhabaghure.brochures.website_internal_url'));
    }

    /** The published landing page of a published package, if it has one. */
    public function publishedPage(TourPackage $package): ?TourLandingPage
    {
        if ($package->status !== PackageStatus::Published) {
            return null;
        }

        return TourLandingPage::query()->where('tour_package_id', $package->id)->where('status', ContentStatus::Published)->first();
    }

    /**
     * For a customer's download: the stored PDF, or a new one stored for next time. Null — and nothing stored — when it
     * can't be made complete; the caller then gives the photo brochure.
     */
    public function stored(TourLandingPage $page, string $locale): ?string
    {
        try {
            $path = "brochures/landing/{$page->id}/".$this->key($page, $locale).'.pdf';
            $disk = Storage::disk('local');
            if ($disk->exists($path)) {
                return (string) $disk->get($path);
            }
            $bytes = $this->render($page, $locale, preview: false);
            // Copies made before today are stale: the folder keeps one day's.
            $today = now('Asia/Dhaka')->startOfDay()->getTimestamp();
            foreach ($disk->files(dirname($path)) as $old) {
                if ($disk->lastModified($old) < $today) {
                    $disk->delete($old);
                }
            }
            $disk->put($path, $bytes);

            return $bytes;
        } catch (Throwable $exception) {
            Log::warning('Landing page brochure failed; the photo brochure is given instead.', ['landing_page_id' => $page->id, 'locale' => $locale, 'error' => Str::limit($exception->getMessage(), 1000)]);

            return null;
        }
    }

    /**
     * Prints the page; nothing is stored. A preview prints the page as saved, draft or not, through the website's staff
     * preview — the draft is never put on a public address. Throws when it can't be made complete.
     */
    public function render(TourLandingPage $page, string $locale, bool $preview): string
    {
        $website = rtrim((string) config('bhabaghure.brochures.website_internal_url'), '/');
        if ($website === '') {
            throw new RuntimeException('WEBSITE_INTERNAL_URL is not set.');
        }
        $page->loadMissing(['tourPackage.itineraryDays', 'tourPackage.images.media', 'hero', 'dayPhotos.media']);
        $package = $page->tourPackage ?? throw new RuntimeException("Landing page {$page->id} has no package.");

        $dir = storage_path('app/private/tmp/pdf/'.Str::random(24));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        try {
            // Every photo the page prints, as a JPEG file the renderer serves for each of its addresses.
            $assets = [];
            foreach ($this->photos($page, $package) as $index => [$media, $variant]) {
                $jpeg = BrochurePhotos::jpeg($media, $variant) ?? throw new RuntimeException("Photo {$media->id} is missing or unreadable.");
                $file = "{$dir}/{$index}.jpg";
                file_put_contents($file, $jpeg);
                foreach ($this->addresses($media) as $url) {
                    $assets[$url] = $file;
                }
            }

            $asOf = now('Asia/Dhaka')->toDateString();
            $path = ($locale === 'en' ? '/en' : '').($preview ? '/tours/preview/print' : "/tours/{$page->slug}/print");

            return $this->renderer->render([
                'url' => "{$website}{$path}?asOf={$asOf}",
                'headers' => $preview ? ['x-preview-token' => LandingPreviewToken::issue($page)['token']] : [],
                'staticPrefix' => "{$website}/_next/static/",
                'assets' => $assets,
            ]);
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /** Everything printed: the page's presentation, the package, its gallery photos and dates, prices, contact, release, day. */
    public function key(TourLandingPage $page, string $locale): string
    {
        $package = $page->tourPackage;
        $config = PricingConfig::current();

        return sha1(json_encode([
            self::VERSION, $locale, Release::id(), now('Asia/Dhaka')->toDateString(),
            $page->only(['id', 'slug', 'status', 'title_bn', 'title_en', 'intro_bn', 'intro_en', 'hero_media_id', 'countdown_departure_id']),
            $page->updated_at?->getTimestamp(),
            $page->dayPhotos()->orderBy('day_number')->orderBy('sort_order')->get(['day_number', 'media_id'])->toArray(),
            $package->id, $package->updated_at?->getTimestamp(), BrochurePdf::printedContent($package->loadMissing(['itineraryDays', 'inclusions', 'destination'])),
            BrochurePdf::upcomingDepartures($package),
            BrochurePhotos::fingerprint($package),
            [$config->slabs, $config->singleRoomSupplementPercent, $config->serviceChargePercent],
            SiteSetting::query()->whereIn('key', SiteSettingKeys::PUBLIC)->orderBy('key')->get(['key', 'value', 'updated_at'])->toArray(),
            Brand::fingerprint(),
        ]));
    }

    /**
     * The photos the page shows: the banner (the package's cover when none is chosen), those beside days the package
     * has, and the gallery's memories not already on the page — as PublicContent::tourLandingPage gives them. Each with
     * the size it is prepared from: the large ones from the 1600 px copy, the grid's small ones from the 800 px one.
     *
     * @return list<array{0: Media, 1: MediaVariant}>
     */
    private function photos(TourLandingPage $page, TourPackage $package): array
    {
        $hero = $page->hero ?? $package->images->first()?->media;
        $days = $package->itineraryDays->pluck('day_number')->all();
        $dayPhotos = $page->dayPhotos->filter(fn (TourLandingDayPhoto $photo) => in_array($photo->day_number, $days, true))->map->media->filter()->all();
        $used = collect([$page->hero_media_id ?? $package->images()->value('media_id'), ...$page->dayPhotos->pluck('media_id')])->filter()->all();
        $memories = array_map(fn (TourPhoto $photo) => $photo->image, array_filter(BrochurePhotos::gallery($package), fn (TourPhoto $photo) => ! in_array($photo->media_id, $used, true)));

        $large = collect([$hero, ...$dayPhotos])->filter()->unique('id');
        $small = collect($memories)->filter()->unique('id')->reject(fn (Media $media) => $large->contains('id', $media->id));

        return [
            ...$large->map(fn (Media $media) => [$media, MediaVariant::Detail])->values()->all(),
            ...$small->map(fn (Media $media) => [$media, MediaVariant::Card])->values()->all(),
        ];
    }

    /** @return list<string> every address the website may use for this photo */
    private function addresses(Media $media): array
    {
        $urls = array_column($media->variantUrls(), 'url');
        foreach (MediaVariant::cases() as $variant) {
            $urls[] = (string) $media->url($variant);
        }

        return array_values(array_unique(array_filter($urls)));
    }
}
