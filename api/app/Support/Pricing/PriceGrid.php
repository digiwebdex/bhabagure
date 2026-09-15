<?php

namespace App\Support\Pricing;

use App\Enums\HotelCategory;

/**
 * A package's hotel-category price grid as stored in tour_packages.price_grid (docs/phase-8-visa-quotes-pricing-downloads.md
 * §4.D): {"3": {"1": 95000, "2": 75000, …}, "4": {…}}. The prices themselves are worked out by PricingService.
 */
final class PriceGrid
{
    /**
     * What the editor sent, tidied: only known categories and tiers, whole-taka prices, blank cells and empty rows dropped.
     * Null when nothing is left — the package is priced the old way.
     *
     * @return array<string, array<string, int>>|null
     */
    public static function normalize(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }
        $grid = [];
        foreach (PricingService::HOTEL_CATEGORIES as $category) {
            $row = [];
            foreach (PricingService::GRID_TIERS as $tier) {
                $value = $input[$category][(string) $tier] ?? null;
                if (is_numeric($value)) {
                    $row[(string) $tier] = (int) round((float) $value);
                }
            }
            if ($row !== []) {
                $grid[$category] = $row;
            }
        }

        return $grid === [] ? null : $grid;
    }

    /**
     * The one price older screens show for a grid package (the admin list, the assistant, structured data): the first
     * offered category's price for two travellers. Null without a grid.
     *
     * @param  array<string, array<string, int|float>>|null  $grid
     */
    public static function referencePrice(?array $grid): ?int
    {
        $categories = PricingService::gridCategories($grid);

        return $categories === [] ? null : PricingService::gridRate($grid, $categories[0], 2)['perPerson'];
    }

    /**
     * The chosen category's row, as a booking or quotation keeps it.
     *
     * @param  array<string, array<string, int|float>>|null  $grid
     * @return array<string, array<string, int|float>>|null
     */
    public static function rowFor(?array $grid, ?string $category): ?array
    {
        return $category !== null && isset($grid[$category]) ? [$category => $grid[$category]] : null;
    }

    /** "NEPAL MUSTANG … · 4-star hotel" — how a booking or quotation line names the category. */
    public static function lineTitle(string $title, ?string $category, string $locale): string
    {
        $label = $category === null ? null : HotelCategory::tryFrom($category)?->label($locale);

        return $label === null ? $title : $title.' · '.$label.($locale === 'en' ? ' hotel' : ' হোটেল');
    }
}
