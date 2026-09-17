<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/partners-and-payments.md: the airlines the agency books, shown as a band of logos above the footer. */
class AirlinePartnersTest extends TestCase
{
    use RefreshDatabase;

    private function logo(string $name): Media
    {
        return Media::query()->create(['disk' => 'public', 'mime' => 'image/svg+xml', 'source_url' => "https://logos.example.test/{$name}.svg", 'is_placeholder' => false, 'alt_en' => $name]);
    }

    #[Test]
    public function a_partner_needs_its_logo_before_it_can_be_published_and_then_reaches_the_website(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');

        $this->getJson('/api/v1/public/partners')->assertOk()->assertExactJson(['data' => []]);

        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/airline-partners', ['name_bn' => 'বিমান বাংলাদেশ এয়ারলাইন্স', 'name_en' => 'Biman Bangladesh Airlines'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.logo', null)->json('data');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/airline-partners/{$created['id']}/publish")->assertUnprocessable()
            ->assertJsonPath('problems', ['Add the airline’s logo.']);

        $logo = $this->logo('biman');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/airline-partners/{$created['id']}", [...$created, 'media_id' => $logo->id, 'website_url' => 'https://www.biman-airlines.com'])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/airline-partners/{$created['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['partners']);

        $this->getJson('/api/v1/public/partners')->assertOk()->assertExactJson(['data' => [[
            'name' => ['bn' => 'বিমান বাংলাদেশ এয়ারলাইন্স', 'en' => 'Biman Bangladesh Airlines'],
            'logo' => [
                'url' => 'https://logos.example.test/biman.svg',
                'alt' => ['bn' => 'biman', 'en' => 'biman'],
                'credit' => null, 'creditUrl' => null, 'isPlaceholder' => false, 'width' => null, 'height' => null,
                // A logo linked from elsewhere has no stored sizes of its own; an uploaded one carries all four.
                'variants' => [],
            ],
            'websiteUrl' => 'https://www.biman-airlines.com',
        ]]]);
        $this->assertSame(['cms.airline_partner.created', 'cms.airline_partner.updated', 'cms.airline_partner.published'],
            AuditLog::query()->where('auditable_type', 'airline_partner')->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function partners_show_in_the_order_staff_set_and_an_unpublished_one_drops_out(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');
        $ids = [];
        foreach (['Scoot', 'AirAsia', 'IndiGo'] as $name) {
            $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/airline-partners', ['name_bn' => $name, 'name_en' => $name, 'media_id' => $this->logo($name)->id])->assertCreated()->json('data.id');
            $this->actingAsApi($admin)->postJson("/api/v1/admin/airline-partners/{$id}/publish")->assertOk();
            $ids[$name] = $id;
        }

        $this->actingAsApi($admin)->putJson('/api/v1/admin/airline-partners/order', ['ids' => [$ids['IndiGo'], $ids['Scoot'], $ids['AirAsia']]])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/airline-partners/{$ids['AirAsia']}/unpublish")->assertOk();

        $this->assertSame(['IndiGo', 'Scoot'], array_column($this->getJson('/api/v1/public/partners')->assertOk()->json('data.*.name'), 'en'));
    }

    #[Test]
    public function a_logo_a_partner_shows_cannot_be_deleted_from_the_media_library(): void
    {
        $admin = $this->staff('admin');
        $logo = $this->logo('scoot');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/airline-partners', ['name_bn' => 'স্কুট', 'name_en' => 'Scoot', 'media_id' => $logo->id])->assertCreated();

        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$logo->id}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');
    }

    #[Test]
    public function a_link_must_be_a_web_address_and_only_website_staff_may_edit_the_list(): void
    {
        $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/airline-partners', ['name_bn' => 'স্কুট', 'name_en' => 'Scoot', 'website_url' => 'scoot dot com'])
            ->assertUnprocessable()->assertJsonValidationErrors('website_url');
        $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/airline-partners', ['name_en' => 'Scoot'])
            ->assertUnprocessable()->assertJsonValidationErrors('name_bn');

        foreach (['tour_operator', 'sales_agent', 'accountant'] as $role) {
            $this->actingAsApi($this->staff($role))->getJson('/api/v1/admin/airline-partners')->assertForbidden();
        }
    }
}
