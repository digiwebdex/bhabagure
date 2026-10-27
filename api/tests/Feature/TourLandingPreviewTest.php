<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TourLandingPage;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A staff preview of a landing page as saved, draft or not (docs/tour-landing-pages.md §6). */
class TourLandingPreviewTest extends TestCase
{
    use RefreshDatabase;

    private TourLandingPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
        $package = TourPackage::query()->where('slug', 'nepal-mustang-adventure-tour-8-days-7-nights')->sole();
        // A draft page on a draft tour: nothing of it is public.
        $package->update(['trip_type' => TourPackage::GROUP_FIXED, 'status' => 'draft', 'overview_en' => 'Draft overview.']);
        $this->page = TourLandingPage::query()->create(['slug' => 'mustang-valley-trip', 'tour_package_id' => $package->id, 'intro_en' => 'Draft intro.']);
        $this->page->forceFill(['status' => 'draft'])->save();
    }

    #[Test]
    public function website_staff_get_a_link_whose_token_shows_the_saved_draft_and_nothing_else(): void
    {
        $response = $this->actingAsApi($this->staff('admin'))->postJson("/api/v1/admin/tour-landing-pages/{$this->page->id}/preview-link")->assertCreated();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $link = $response->json('data');
        $this->assertStringContainsString('/tours/preview#', $link['url_bn']);
        $this->assertStringContainsString('/en/tours/preview#', $link['url_en']);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), strtotime($link['expires_at']), 5);
        $token = explode('#', $link['url_bn'])[1];
        // Recorded without the token.
        $audit = AuditLog::query()->where('action', 'cms.tour_landing_page.preview_link')->sole();
        $this->assertStringNotContainsString($token, json_encode($audit->toArray()));

        $preview = $this->withHeader('X-Preview-Token', $token)->getJson('/api/v1/public/tour-pages/preview')->assertOk();
        $preview->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertSame(['mustang-valley-trip', ['bn' => 'Draft intro.', 'en' => 'Draft intro.']], [$preview->json('data.page.slug'), $preview->json('data.page.intro')]);
        $this->assertSame('draft', $preview->json('data.package.status'));
        $this->assertSame(['bn' => 'Draft overview.', 'en' => 'Draft overview.'], $preview->json('data.package.overview'));

        // Nowhere public: not the list, not the package.
        $this->flushHeaders();
        $this->getJson('/api/v1/public/tour-pages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/packages/nepal-mustang-adventure-tour-8-days-7-nights')->assertNotFound();
    }

    #[Test]
    public function an_expired_tampered_or_missing_token_gets_no_draft(): void
    {
        $token = explode('#', $this->actingAsApi($this->staff('admin'))->postJson("/api/v1/admin/tour-landing-pages/{$this->page->id}/preview-link")->json('data.url_en'))[1];
        [$payload, $signature] = explode('.', $token);

        foreach ([
            'tampered signature' => $payload.'.'.strrev($signature),
            'another page' => rtrim(strtr(base64_encode('999.'.(time() + 600)), '+/', '-_'), '=').'.'.$signature,
            'garbage' => 'not-a-token',
        ] as $case => $bad) {
            $this->flushHeaders();
            $response = $this->withHeader('X-Preview-Token', $bad)->getJson('/api/v1/public/tour-pages/preview')->assertNotFound();
            $this->assertNull($response->json('data'), $case);
        }
        $this->flushHeaders();
        $this->getJson('/api/v1/public/tour-pages/preview')->assertNotFound();

        // 31 minutes later the good one is refused too.
        $this->travel(31)->minutes();
        $this->flushHeaders();
        $this->withHeader('X-Preview-Token', $token)->getJson('/api/v1/public/tour-pages/preview')->assertNotFound()->assertJsonMissingPath('data');
    }

    #[Test]
    public function only_staff_who_manage_the_website_make_preview_links(): void
    {
        $this->postJson("/api/v1/admin/tour-landing-pages/{$this->page->id}/preview-link")->assertUnauthorized();
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/tour-landing-pages/{$this->page->id}/preview-link")->assertForbidden();
    }

    #[Test]
    public function preview_is_not_a_pages_address(): void
    {
        $package = TourPackage::query()->where('trip_type', 'customized')->firstOrFail();
        $package->update(['trip_type' => TourPackage::GROUP_FIXED]);

        $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/tour-landing-pages', ['slug' => 'preview', 'tour_package_id' => $package->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
    }
}
