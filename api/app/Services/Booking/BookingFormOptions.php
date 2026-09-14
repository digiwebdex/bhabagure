<?php

namespace App\Services\Booking;

use App\Enums\LeadSource;
use App\Models\Addon;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use App\Support\Money;
use App\Support\Pricing\PricingConfig;

/**
 * What the staff booking form and the quotation editor can offer: published packages with their upcoming departures,
 * active add-ons and the pricing settings @bhabaghure/pricing quotes with — the same inputs the API prices the save with.
 */
final class BookingFormOptions
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        $today = now('Asia/Dhaka')->toDateString();
        $config = PricingConfig::current();

        return [
            'packages' => TourPackage::query()->published()->orderBy('sort_order')->orderBy('id')
                ->with(['departures' => fn ($q) => $q->where('status', 'scheduled')->whereDate('departs_on', '>=', $today)->orderBy('departs_on')])
                ->get()
                ->map(fn (TourPackage $package) => [
                    'slug' => $package->slug,
                    'title_en' => $package->title_en,
                    'title_bn' => $package->title_bn,
                    'duration_days' => $package->duration_days,
                    'list_price' => Money::toNumber($package->sale_price ?? $package->regular_price),
                    'departures' => $package->departures->map(fn (PackageDeparture $departure) => [
                        'date' => $departure->departs_on->toDateString(),
                        'seats_left' => $departure->seats_total === null ? null : DepartureSeats::available($departure),
                    ])->values(),
                ])->values(),
            'addons' => Addon::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Addon $addon) => ['code' => $addon->code, 'name_en' => $addon->name_en, 'name_bn' => $addon->name_bn, 'price' => Money::toNumber($addon->price), 'unit' => $addon->unit])->values(),
            'config' => [
                'slabs' => $config->slabs, 'singleRoomSupplementPercent' => $config->singleRoomSupplementPercent,
                'serviceChargePercent' => $config->serviceChargePercent, 'maxTravellers' => $config->maxTravellers,
                'onlinePaymentChargePercent' => $config->onlinePaymentChargePercent,
            ],
            'sources' => array_map(fn (LeadSource $source) => $source->value, LeadSource::forCustomers()),
        ];
    }

    /** @return list<int|float> the VAT / service charge rates staff may pick: the fixed set plus the configured default */
    public static function vatRates(): array
    {
        $rates = BookingQuoteEditor::VAT_RATES;
        $default = PricingConfig::current()->serviceChargePercent;
        if (! in_array($default, $rates, false)) {
            $rates[] = $default;
            sort($rates);
        }

        return array_values($rates);
    }
}
