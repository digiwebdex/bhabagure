<?php

namespace App\Services\Brochures;

use App\Enums\HotelCategory;
use App\Models\TourPackage;
use App\Models\VisaService;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
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
    /** Bump when a template changes, so stored PDFs are made again. */
    public const TEMPLATE_VERSION = 1;

    /** Group sizes a package without a grid is priced for in the brochure. */
    private const SLAB_SIZES = [1, 2, 3, 4, 6, 10];

    public function __construct(private readonly InvoicePdf $renderer, private readonly InvoiceView $invoices) {}

    /** Expects destination, itineraryDays and inclusions loaded. The chosen category and group size are highlighted. */
    public function packageHtml(TourPackage $package, ?string $category, int $pax, string $locale, bool $forPdf = false): string
    {
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
            'yourPrice' => $categories !== [] ? PricingService::gridRate($package->price_grid, $category, $pax)['perPerson'] : PricingService::perPersonRate($listPrice, $pax, $config->slabs),
            'asOf' => now('Asia/Dhaka')->toDateString(),
        ])->render();
    }

    public function packagePdf(TourPackage $package, ?string $category, int $pax, string $locale): string
    {
        $config = PricingConfig::current();
        $key = sha1(implode('|', [self::TEMPLATE_VERSION, 'package', $package->id, $package->updated_at?->getTimestamp(), $category, $pax, $locale,
            json_encode($config->slabs), $config->singleRoomSupplementPercent, $config->serviceChargePercent, now('Asia/Dhaka')->toDateString()]));

        return $this->stored("brochures/packages/{$package->id}/{$key}.pdf", fn () => $this->packageHtml($package, $category, $pax, $locale, forPdf: true));
    }

    public function visaHtml(VisaService $visa, string $locale, bool $forPdf = false): string
    {
        return view('brochures.visa', $this->invoices->letterhead($locale) + [
            'locale' => $locale,
            'forPdf' => $forPdf,
            'visa' => $visa,
            'requirements' => $visa->requirementLists()[$locale === 'en' ? 'en' : 'bn'],
            'asOf' => now('Asia/Dhaka')->toDateString(),
        ])->render();
    }

    public function visaPdf(VisaService $visa, string $locale): string
    {
        $key = sha1(implode('|', [self::TEMPLATE_VERSION, 'visa', $visa->id, $visa->updated_at?->getTimestamp(), $locale, now('Asia/Dhaka')->toDateString()]));

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
