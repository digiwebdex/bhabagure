<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use App\Services\Brochures\BrochurePdf;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A package's shareable page and how staff make more like it (docs/package-page.md). */
class PackagePageTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private TourPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
        $this->package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
    }

    #[Test]
    public function the_overview_is_saved_shown_publicly_and_printed_in_the_brochure(): void
    {
        $operator = $this->staff('tour_operator');
        $detail = $this->actingAsApi($operator)->getJson("/api/v1/admin/packages/{$this->package->id}")->assertOk()->json('data');
        $this->assertNull($detail['overview_en']);
        $this->getJson('/api/v1/public/packages/'.self::MUSTANG)->assertOk()->assertJsonPath('data.overview', null);

        $overview = "Seven days through the Kali Gandaki valley.\n\nThe group travels together from Dhaka.";
        $this->actingAsApi($operator)->putJson("/api/v1/admin/packages/{$this->package->id}", [...$this->editable($detail), 'overview_en' => $overview, 'overview_bn' => '  '])
            ->assertOk()->assertJsonPath('data.overview_en', $overview)->assertJsonPath('data.overview_bn', null);

        // Bangla falls back to the English text until it is written.
        $this->getJson('/api/v1/public/packages/'.self::MUSTANG)->assertOk()->assertJsonPath('data.overview', ['bn' => $overview, 'en' => $overview]);

        $html = app(BrochurePdf::class)->packageHtml($this->package->fresh(['destination', 'itineraryDays', 'inclusions']), null, 2, 'en');
        $this->assertStringContainsString('Overview', $html);
        $this->assertStringContainsString("Seven days through the Kali Gandaki valley.\n\nThe group travels together from Dhaka.", $html);
    }

    #[Test]
    public function without_an_overview_the_brochure_has_no_empty_section(): void
    {
        $html = app(BrochurePdf::class)->packageHtml($this->package->fresh(['destination', 'itineraryDays', 'inclusions']), null, 2, 'en');

        $this->assertStringNotContainsString('class="overview"', $html);
    }

    #[Test]
    public function duplicating_makes_a_draft_with_the_text_itinerary_and_prices_but_not_the_photos_or_dates(): void
    {
        $operator = $this->staff('tour_operator');
        $this->package->update(['overview_en' => 'About Mustang.', 'is_featured' => true]);
        PackageDeparture::query()->create(['tour_package_id' => $this->package->id, 'departs_on' => now()->addDays(30)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled']);
        $source = $this->package->fresh(['itineraryDays', 'inclusions', 'tags', 'images']);
        $this->assertNotEmpty($source->images);

        $copy = $this->actingAsApi($operator)->postJson("/api/v1/admin/packages/{$this->package->id}/duplicate")->assertCreated()->json('data');

        $this->assertSame(['draft', $source->code.'-COPY', $source->slug.'-copy', $source->title_en.' (copy)', false], [$copy['status'], $copy['code'], $copy['slug'], $copy['title_en'], $copy['is_featured']]);
        $this->assertSame([$source->duration_days, (float) $source->regular_price, 'About Mustang.', $source->destination_id], [$copy['duration_days'], (float) $copy['regular_price'], $copy['overview_en'], $copy['destination_id']]);
        $this->assertSame($source->itineraryDays->pluck('title_en')->all(), array_column($copy['itinerary'], 'title_en'));
        $this->assertCount($source->inclusions->where('kind', 'include')->count(), $copy['includes']);
        $this->assertSame($source->tags->where('type', 'activity')->pluck('name_en')->values()->all(), $copy['activities']);
        $this->assertSame([], $copy['images']);
        $this->assertSame(0, PackageDeparture::query()->where('tour_package_id', $copy['id'])->count());
        $this->assertTrue(AuditLog::query()->where('action', 'cms.package.duplicated')->where('auditable_id', $copy['id'])->exists());
        // The original is untouched, and the draft is not on the website.
        $this->assertSame($source->updated_at?->getTimestamp(), $this->package->fresh()->updated_at?->getTimestamp());
        $this->getJson('/api/v1/public/packages/'.$copy['slug'])->assertNotFound();

        // Again: the next free code and address.
        $again = $this->actingAsApi($operator)->postJson("/api/v1/admin/packages/{$this->package->id}/duplicate")->assertCreated()->json('data');
        $this->assertSame([$source->code.'-COPY2', $source->slug.'-copy-2'], [$again['code'], $again['slug']]);
    }

    #[Test]
    public function only_staff_who_manage_packages_can_duplicate(): void
    {
        $this->postJson("/api/v1/admin/packages/{$this->package->id}/duplicate")->assertUnauthorized();
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/packages/{$this->package->id}/duplicate")->assertForbidden();
        $this->assertSame(0, TourPackage::query()->where('code', 'like', '%-COPY%')->count());
    }

    /** @param array<string, mixed> $detail */
    private function editable(array $detail): array
    {
        return collect($detail)->only([
            'code', 'slug', 'destination_id', 'title_en', 'title_bn', 'summary_en', 'summary_bn', 'duration_days', 'duration_nights', 'regular_price',
            'sale_price', 'price_grid', 'price_options', 'includes_airfare', 'group_mode', 'min_pax', 'departure_mode', 'trip_type',
            'single_supplement_percent', 'twin_supplement_percent', 'itinerary', 'includes', 'excludes', 'activities', 'trip_types',
        ])->all();
    }
}
