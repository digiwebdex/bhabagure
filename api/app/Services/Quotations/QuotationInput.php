<?php

namespace App\Services\Quotations;

/** What a staff member priced in the quotation editor, already validated. Prices are never part of it — only the total they saw. */
final class QuotationInput
{
    /** @param  list<string>  $addonCodes */
    public function __construct(
        public readonly string $packageSlug,
        public readonly ?string $travelDate,
        public readonly int $pax,
        public readonly string $room,
        public readonly array $addonCodes,
        public readonly int|float $discount,
        public readonly int|float $vatRate,
        public readonly int $validityDays,
        public readonly string $locale,
        public readonly ?string $notes,
        public readonly int|float $expectedTotal,
    ) {}
}
