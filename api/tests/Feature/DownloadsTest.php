<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Download;
use App\Models\TourPackage;
use App\Models\VisaService;
use App\Services\Brochures\BrochurePdf;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.E: package brochures and visa requirements as PDFs, only for a signed-in
 * customer, every download logged for the Downloads screen and the customer's profile.
 */
class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    /** @var list<string> the HTML the renderer was given */
    private array $rendered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
        $rendered = &$this->rendered;
        $this->app->instance(InvoicePdf::class, new class(app(InvoiceView::class), $rendered) extends InvoicePdf
        {
            public function __construct(InvoiceView $view, private array &$rendered)
            {
                parent::__construct($view);
            }

            public function render(string $html): string
            {
                $this->rendered[] = $html;

                return '%PDF-1.4 test brochure';
            }
        });
    }

    #[Test]
    public function only_a_signed_in_customer_downloads_and_each_download_is_logged_with_the_choice(): void
    {
        $this->getJson('/api/v1/portal/downloads/packages/'.self::MUSTANG)->assertUnauthorized();

        $customer = $this->lead();
        $response = $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?pax=4&locale=en')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-1.4 test brochure', $response->getContent());
        $this->assertStringContainsString('attachment; filename="bhabaghure-'.self::MUSTANG.'.pdf"', (string) $response->headers->get('Content-Disposition'));

        $download = Download::query()->sole();
        $package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $this->assertSame([$customer->id, 'package', $package->id, $package->title_en, null, 4, 'en'], [$download->customer_id, $download->kind, $download->tour_package_id, $download->title, $download->hotel_category, $download->pax, $download->locale]);

        // The brochure: the price table by group size (75,000 list; 4 travellers −6% = 70,500), the itinerary and inclusions.
        $html = $this->rendered[0];
        $this->assertStringContainsString('৳ 70,500', $html);
        $this->assertStringContainsString('Price per person', $html);
        $this->assertStringContainsString($package->itineraryDays()->orderBy('day_number')->first()->title_en ?? 'Day', $html);
        $this->assertStringContainsString('Included', $html);

        // The same brochure again is served from storage, and logged again.
        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?pax=4&locale=en')->assertOk();
        $this->assertCount(1, $this->rendered);
        $this->assertSame(2, Download::query()->count());

        // A new choice is made today; a copy from an earlier day in the package's folder is dropped then.
        $disk = Storage::disk('local');
        $disk->put("brochures/packages/{$package->id}/yesterday.pdf", 'old');
        touch($disk->path("brochures/packages/{$package->id}/yesterday.pdf"), now()->subDays(2)->getTimestamp());
        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?pax=2&locale=en')->assertOk();
        $this->assertCount(2, $this->rendered);
        $this->assertFalse($disk->exists("brochures/packages/{$package->id}/yesterday.pdf"));
        $this->assertCount(2, $disk->files("brochures/packages/{$package->id}"));

        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/no-such-package')->assertNotFound();
        $this->actingAsApi($customer)->getJson('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?pax=0')->assertUnprocessable();
    }

    #[Test]
    public function a_grid_package_brochure_prices_every_sold_category_and_marks_the_chosen_one(): void
    {
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => json_encode(['3' => ['1' => 95000, '2' => 75000, '4' => 70000], '4' => ['1' => 120000, '2' => 90000]])]);
        $customer = $this->lead();

        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?hotel_category=4&pax=3&locale=bn')->assertOk();

        $html = $this->rendered[0];
        $this->assertStringContainsString('বেসিক / ৩ তারকা', $html);
        $this->assertStringContainsString('<td class="num chosen">৳ ৯০,০০০</td>', $html);
        $this->assertStringContainsString('৪ তারকা হোটেল', $html);
        $this->assertSame(['4', 3], [Download::query()->sole()->hotel_category, Download::query()->sole()->pax]);

        // A grid that sells no 3★ row: asked for nothing (or a category it doesn't sell), the brochure and the log mean the first one sold.
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => json_encode(['4' => ['1' => 120000, '2' => 90000], '5' => ['1' => 160000, '2' => 130000]])]);
        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?hotel_category=3&pax=2&locale=en')->assertOk();
        $this->assertSame('4', Download::query()->latest('id')->first()->hotel_category);
        $this->assertStringContainsString('<td class="num chosen">৳ 90,000</td>', $this->rendered[1]);
    }

    #[Test]
    public function a_visa_requirements_pdf_lists_the_requirements_and_draft_visas_are_not_offered(): void
    {
        $visa = VisaService::query()->create([
            'slug' => 'thailand-tourist-visa', 'country_code' => 'TH', 'country_bn' => 'থাইল্যান্ড', 'country_en' => 'Thailand', 'visa_type_bn' => 'টুরিস্ট ভিসা', 'visa_type_en' => 'Tourist visa',
            'price' => 5500, 'processing_en' => '7–10 working days', 'requirements_en' => "Passport valid for 6 months\nTwo photos", 'requirements_bn' => "ছয় মাস মেয়াদি পাসপোর্ট\nদুই কপি ছবি", 'status' => 'draft',
        ]);
        $customer = $this->lead();

        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/visas/thailand-tourist-visa')->assertNotFound();
        $visa->forceFill(['status' => 'published'])->save();
        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/visas/thailand-tourist-visa?locale=en')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="bhabaghure-visa-thailand-tourist-visa.pdf"');

        $this->assertStringContainsString('<li>Passport valid for 6 months</li>', $this->rendered[0]);
        $this->assertStringContainsString('৳ 5,500 per person', $this->rendered[0]);
        $this->assertSame(['visa', $visa->id, 'Thailand Tourist visa'], [Download::query()->sole()->kind, Download::query()->sole()->visa_service_id, Download::query()->sole()->title]);
    }

    #[Test]
    public function the_downloads_screen_shows_who_downloaded_what_totals_and_who_still_needs_a_follow_up(): void
    {
        $package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $follow = $this->lead(['name' => 'Rahim Uddin', 'phone' => '8801711000401']);
        $booked = $this->lead(['name' => 'Karima Begum', 'phone' => '8801711000402']);
        foreach ([[$follow, '2026-09-10 10:00:00'], [$follow, '2026-09-12 10:00:00'], [$booked, '2026-09-13 10:00:00']] as [$customer, $at]) {
            Download::query()->forceCreate(['customer_id' => $customer->id, 'kind' => 'package', 'tour_package_id' => $package->id, 'title' => $package->title_en, 'hotel_category' => '4', 'pax' => 2, 'locale' => 'bn', 'created_at' => $at]);
        }
        Download::query()->forceCreate(['customer_id' => $booked->id, 'kind' => 'visa', 'title' => 'Thailand Tourist visa', 'locale' => 'en', 'created_at' => '2026-09-14 10:00:00']);
        Booking::query()->create([
            'reference' => 'BH-2026-900', 'customer_id' => $booked->id, 'package_title_en' => 'Mustang Valley Adventure', 'pax_count' => 2, 'list_price' => 75000,
            'unit_price' => 75000, 'subtotal_amount' => 150000, 'discount_amount' => 0, 'vat_amount' => 0, 'total_amount' => 150000, 'source' => 'website_form',
        ]);

        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/downloads')->assertForbidden();
        $super = $this->staff('super_admin');

        $page = $this->actingAsApi($super)->getJson('/api/v1/admin/downloads')->assertOk()
            ->assertJsonPath('meta.totals', ['downloads' => 4, 'customers' => 2, 'packages' => 3, 'visas' => 1])->json('data');
        $this->assertSame(['Thailand Tourist visa', $package->title_en, $package->title_en, $package->title_en], array_column($page, 'title'));
        $this->assertSame(['booking' => true, 'quotation' => false], $page[0]['in_progress']);
        $this->assertSame(['Rahim Uddin', 2, ['booking' => false, 'quotation' => false]], [$page[2]['customer']['name'], $page[2]['customer']['downloads'], $page[2]['in_progress']]);
        $this->assertSame(['4', 2, self::MUSTANG], [$page[1]['hotel_category'], $page[1]['pax'], $page[1]['slug']]);

        $this->actingAsApi($super)->getJson('/api/v1/admin/downloads?follow_up=1')->assertJsonPath('meta.totals.customers', 1)->assertJsonPath('data.0.customer.name', 'Rahim Uddin');
        $this->actingAsApi($super)->getJson('/api/v1/admin/downloads?kind=visa')->assertJsonPath('meta.total', 1);
        $this->actingAsApi($super)->getJson('/api/v1/admin/downloads?search=01711000401')->assertJsonPath('meta.total', 2);
        $this->actingAsApi($super)->getJson('/api/v1/admin/downloads?search=Karima')->assertJsonPath('meta.total', 2);
        $this->actingAsApi($super)->getJson('/api/v1/admin/downloads?search=Thailand')->assertJsonPath('meta.total', 1);
        $this->actingAsApi($super)->getJson('/api/v1/admin/downloads?from=2026-09-12&to=2026-09-13')->assertJsonPath('meta.total', 2);

        // Granted on the Roles screen, an admin sees it too; the customer's profile lists their downloads.
        $admin->givePermissionTo('downloads.view');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/downloads')->assertOk();
        $this->actingAsApi($admin)->getJson("/api/v1/admin/customers/{$follow->id}")->assertOk()->assertJsonCount(2, 'data.downloads')->assertJsonPath('data.downloads.0.created_at', '2026-09-12T10:00:00+00:00');
        $this->assertNotNull(Booking::query()->where('reference', 'BH-2026-900')->first());
    }

    #[Test]
    public function a_customer_whose_portal_is_turned_off_cannot_download(): void
    {
        $customer = $this->lead(['portal_disabled_at' => now()]);
        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG)->assertUnauthorized();
        $this->assertInstanceOf(BrochurePdf::class, app(BrochurePdf::class));
    }

    /** @param array<string, mixed> $attributes */
    private function lead(array $attributes = []): Customer
    {
        return Customer::query()->forceCreate(['name' => 'Tanvir Hasan', 'phone' => '8801711000400', 'stage' => 'lead', 'source' => 'website_form', ...$attributes]);
    }
}
