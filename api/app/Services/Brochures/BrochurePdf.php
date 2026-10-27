<?php

namespace App\Services\Brochures;

use App\Enums\HotelCategory;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use App\Models\VisaService;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use App\Support\Brand;
use App\Support\Money;
use App\Support\Pricing\PriceGrid;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The PDFs a signed-in customer downloads from the website (docs/phase-8-visa-quotes-pricing-downloads.md §4.E): a
 * package brochure (price, itinerary, what is included) and a visa's requirements, on the invoices' letterhead and
 * headless-Chrome renderer. Kept on the private disk, keyed by everything that changes what is printed.
 */
class BrochurePdf
{
    /** Bump when a template changes, so stored PDFs are made again. 6: group tours as a photo brochure (2026-10-27). */
    public const TEMPLATE_VERSION = 6;

    /** Group sizes a package without a grid is priced for in the brochure. */
    private const SLAB_SIZES = [1, 2, 3, 4, 6, 10];

    public function __construct(private readonly InvoicePdf $renderer, private readonly InvoiceView $invoices) {}

    /** Expects destination, itineraryDays and inclusions loaded. The chosen category and group size are highlighted. */
    public function packageHtml(TourPackage $package, ?string $category, int $pax, string $locale, bool $forPdf = false): string
    {
        if ($package->isGroupTour()) {
            return $this->groupTourHtml($package, $pax, $locale, $forPdf);
        }
        $config = PricingConfig::current();
        $categories = PricingService::gridCategories($package->price_grid);
        $category = PriceGrid::chosenCategory($package->price_grid, $category);
        $listPrice = Money::toNumber($package->sale_price ?? $package->regular_price);

        // Rows of the price table: one per category with a grid, one row of group sizes without.
        $rows = $categories !== []
            ? array_map(fn (string $c) => [
                'label' => HotelCategory::from($c)->label($locale),
                'category' => $c,
                'cells' => array_map(fn (int $tier) => ['size' => $tier, 'price' => PricingService::gridRate($package->price_grid, $c, $tier)['perPerson'], 'exact' => isset($package->price_grid[$c][(string) $tier])], PricingService::GRID_TIERS),
            ], $categories)
            : [[
                'label' => null,
                'category' => null,
                'cells' => array_map(fn (int $size) => ['size' => $size, 'price' => PricingService::perPersonRate($listPrice, $size, $config->slabs), 'exact' => true], self::SLAB_SIZES),
            ]];

        return view('brochures.package', $this->invoices->letterhead($locale) + [
            'locale' => $locale,
            'forPdf' => $forPdf,
            'package' => $package,
            'rows' => $rows,
            'category' => $category,
            'pax' => $pax,
            'grid' => $categories !== [],
            'config' => $config,
            'rates' => $package->roomRates(),
            'yourPrice' => $categories !== [] ? PricingService::gridRate($package->price_grid, $category, $pax)['perPerson'] : PricingService::perPersonRate($listPrice, $pax, $config->slabs),
            'asOf' => now('Asia/Dhaka')->toDateString(),
        ])->render();
    }

    /**
     * A fixed-departure group tour (docs/fixed-departure-group-tours.md), as a photo brochure (docs/group-tour-brochures.md):
     * its room prices and departure dates, the itinerary and what is included, between photos from the tour and the Group
     * Tour Gallery. With prices that differ by date (docs/departure-prices.md), each date says its price and the room
     * table is for the date the website's card shows — the featured one, else the first.
     */
    private function groupTourHtml(TourPackage $package, int $pax, string $locale, bool $forPdf): string
    {
        $departures = self::upcomingDepartures($package);
        $shown = collect($departures)->firstWhere('featured', true) ?? ($departures[0] ?? null);
        $listPrice = $shown['price'] ?? Money::toNumber($package->sale_price ?? $package->regular_price);
        $rooms = PricingService::roomPrices($listPrice, $package->roomRates(), PricingConfig::current());
        $varies = count(array_unique(array_column($departures, 'price'))) > 1;

        // Photos: a row under the prices, one in the middle of the itinerary, and the rest as a gallery before the end.
        $photos = GroupTourPhotos::for($package, $locale);
        $pool = $photos['photos'];
        $bands = [
            GroupTourPhotos::rows(array_slice($pool, 0, 3), 70, 0),
            GroupTourPhotos::rows(array_slice($pool, 3, 3), 70, 0),
        ];

        return view('brochures.group-tour', $this->invoices->letterhead($locale) + [
            'locale' => $locale,
            'forPdf' => $forPdf,
            'package' => $package,
            'rooms' => $rooms,
            'departures' => $departures,
            'pricedOn' => $varies ? $shown['date'] : null,
            'rates' => $package->roomRates(),
            'pax' => $pax,
            'config' => PricingConfig::current(),
            'hero' => $photos['hero'],
            'bands' => $bands,
            'gallery' => GroupTourPhotos::rows(array_slice($pool, 6), 52, 34),
            'asOf' => now('Asia/Dhaka')->toDateString(),
        ])->render();
    }

    /**
     * A package's scheduled departures from today, each with its price per person: its own, else the package's.
     *
     * @return list<array{date: string, returns: ?string, price: int|float, featured: bool}>
     */
    private static function upcomingDepartures(TourPackage $package): array
    {
        $base = Money::toNumber($package->sale_price ?? $package->regular_price);

        return $package->departures()->where('status', 'scheduled')->whereDate('departs_on', '>=', now('Asia/Dhaka')->toDateString())
            ->orderBy('departs_on')->get()
            ->map(fn (PackageDeparture $departure) => [
                'date' => $departure->departs_on->toDateString(),
                'returns' => $departure->returns_on?->toDateString(),
                'price' => $departure->price === null ? $base : Money::toNumber($departure->price),
                'featured' => $departure->is_featured,
            ])->all();
    }

    public function packagePdf(TourPackage $package, ?string $category, int $pax, string $locale): string
    {
        $config = PricingConfig::current();
        $key = sha1(implode('|', [self::TEMPLATE_VERSION, 'package', $package->id, $package->updated_at?->getTimestamp(), $category, $pax, $locale,
            json_encode($config->slabs), $config->singleRoomSupplementPercent, $config->serviceChargePercent, now('Asia/Dhaka')->toDateString(),
            $package->isGroupTour() ? json_encode(self::upcomingDepartures($package)).GroupTourPhotos::fingerprint($package) : '', Brand::fingerprint()]));

        return $this->stored("brochures/packages/{$package->id}/{$key}.pdf", fn () => $this->packageHtml($package, $category, $pax, $locale, forPdf: true));
    }

    public function visaHtml(VisaService $visa, string $locale, bool $forPdf = false): string
    {
        return view('brochures.visa', $this->invoices->letterhead($locale) + [
            'locale' => $locale,
            'forPdf' => $forPdf,
            'visa' => $visa,
            'groups' => VisaService::groups($visa->requirementLists()[$locale === 'en' ? 'en' : 'bn']),
            'asOf' => now('Asia/Dhaka')->toDateString(),
        ])->render();
    }

    public function visaPdf(VisaService $visa, string $locale): string
    {
        $key = sha1(implode('|', [self::TEMPLATE_VERSION, 'visa', $visa->id, $visa->updated_at?->getTimestamp(), $locale, now('Asia/Dhaka')->toDateString(), Brand::fingerprint()]));

        return $this->stored("brochures/visas/{$visa->id}/{$key}.pdf", fn () => $this->visaHtml($visa, $locale, forPdf: true));
    }

    /** @param callable(): string $html */
    private function stored(string $path, callable $html): string
    {
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }
        // The same lock as invoices: one Chrome at a time on the shared server.
        $bytes = Cache::lock('bhabaghure:invoice-pdf-render', 90)->block(75, fn () => $this->renderer->render($html()));
        // Copies made before today are stale (their key has an older date, an older update or template): drop them, so
        // the folder holds one day's choices per package or visa, not every day's.
        $today = now('Asia/Dhaka')->startOfDay()->getTimestamp();
        foreach ($disk->files(dirname($path)) as $old) {
            if ($disk->lastModified($old) < $today) {
                $disk->delete($old);
            }
        }
        $disk->put($path, $bytes);

        return $bytes;
    }
}
