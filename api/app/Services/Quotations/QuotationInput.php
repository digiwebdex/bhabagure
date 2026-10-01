<?php

namespace App\Services\Quotations;

/** What a staff member priced in the quotation editor, already validated. Prices are never part of it — only the total they saw. */
final class QuotationInput
{
    /**
     * @param  list<string>  $addonCodes
     * @param  array{title: string, details: ?string, items: list<array{title: string, unitPrice: int|float}>}|null  $custom
     */
    public function __construct(
        /** Null for a custom quotation. */
        public readonly ?string $packageSlug,
        public readonly ?string $travelDate,
        public readonly int $pax,
        public readonly string $room,
        public readonly array $addonCodes,
        public readonly int|float $discount,
        public readonly int|float $vatRate,
        public readonly int $validityDays,
        public readonly string $locale,
        /** The note for the customer, printed on the quotation. */
        public readonly ?string $notes,
        public readonly int|float $expectedTotal,
        /** For a package with a hotel-category price grid: '3', '4' or '5' (Phase 8 §4.D). */
        public readonly ?string $hotelCategory = null,
        /** A trip that isn't one of the packages (2026-10-02): its title, details and lines at a price per person. */
        public readonly ?array $custom = null,
        /** Staff only: never printed, linked, shown in the portal or sent. */
        public readonly ?string $internalNote = null,
    ) {}
}
