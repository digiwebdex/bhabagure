<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Download;
use App\Models\TourPackage;
use App\Models\VisaService;
use App\Services\Brochures\BrochurePdf;
use App\Support\Pricing\PriceGrid;
use App\Support\Pricing\PricingConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The website's brochure and visa-requirements downloads (docs/phase-8-visa-quotes-pricing-downloads.md §4.E). Only a
 * signed-in customer gets one (the phone-code sign-in), and every download is logged for the Downloads screen: who,
 * what, in which hotel category and for how many travellers.
 */
class DownloadController extends Controller
{
    public function package(Request $request, string $slug, BrochurePdf $brochures): Response
    {
        $data = $request->validate([
            'hotel_category' => ['nullable', Rule::in(['3', '4', '5'])],
            'pax' => ['nullable', 'integer', 'min:1', 'max:'.PricingConfig::current()->maxTravellers],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);
        $package = TourPackage::query()->published()->where('slug', $slug)->with(['destination', 'itineraryDays', 'inclusions'])->firstOrFail();
        $locale = $data['locale'] ?? app()->getLocale();
        $pax = (int) ($data['pax'] ?? 2);
        $category = PriceGrid::chosenCategory($package->price_grid, $data['hotel_category'] ?? null);

        $bytes = $brochures->packagePdf($package, $category, $pax, $locale);
        $this->log($request, [
            'kind' => Download::PACKAGE, 'tour_package_id' => $package->id, 'title' => $package->title_en,
            'hotel_category' => $category, 'pax' => $pax, 'locale' => $locale,
        ]);

        return $this->pdf($bytes, "bhabaghure-{$package->slug}.pdf");
    }

    public function visa(Request $request, string $slug, BrochurePdf $brochures): Response
    {
        $locale = $request->validate(['locale' => ['nullable', Rule::in(['bn', 'en'])]])['locale'] ?? app()->getLocale();
        $visa = VisaService::query()->published()->where('slug', $slug)->firstOrFail();

        $bytes = $brochures->visaPdf($visa, $locale);
        $this->log($request, ['kind' => Download::VISA, 'visa_service_id' => $visa->id, 'title' => trim("{$visa->country_en} {$visa->visa_type_en}"), 'locale' => $locale]);

        return $this->pdf($bytes, "bhabaghure-visa-{$visa->slug}.pdf");
    }

    /** @param array<string, mixed> $row */
    private function log(Request $request, array $row): void
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        Download::query()->create($row + ['customer_id' => $customer->id, 'ip' => $request->ip()]);
    }

    private function pdf(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
