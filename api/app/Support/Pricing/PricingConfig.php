<?php

namespace App\Support\Pricing;

use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Models\PricingSlab;
use App\Models\SiteSetting;
use App\Support\Money;

/** The same configuration the website receives from GET /public/pricing. */
final class PricingConfig
{
    /** @param list<array{minPax: int, discountPercent: int|float}> $slabs */
    public function __construct(
        public readonly array $slabs,
        public readonly int|float $singleRoomSupplementPercent,
        public readonly int|float $serviceChargePercent,
        public readonly int $maxTravellers,
        public readonly int|float $onlinePaymentChargePercent = 0,
    ) {}

    /** @param array{slabs: list<array{minPax: int, discountPercent: int|float}>, singleRoomSupplementPercent: int|float, serviceChargePercent: int|float, maxTravellers: int, onlinePaymentChargePercent?: int|float} $config */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['slabs'], $config['singleRoomSupplementPercent'], $config['serviceChargePercent'], (int) $config['maxTravellers'],
            $config['onlinePaymentChargePercent'] ?? 0,
        );
    }

    public static function current(): self
    {
        $settings = SiteSetting::get(SiteSettingKeys::PRICING, []);

        return new self(
            PricingSlab::query()->orderBy('min_pax')->get()
                ->map(fn (PricingSlab $slab) => ['minPax' => $slab->min_pax, 'discountPercent' => Money::toNumber($slab->discount_percent)])
                ->all(),
            $settings['singleRoomSupplementPercent'] ?? 0,
            $settings['serviceChargePercent'] ?? 0,
            (int) ($settings['maxTravellers'] ?? 20),
            $settings['onlinePaymentChargePercent'] ?? 0,
        );
    }
}
