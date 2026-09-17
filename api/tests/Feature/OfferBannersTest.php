<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/offer-banners.md: the offer banners that slide under the hero video on the home page. */
class OfferBannersTest extends TestCase
{
    use RefreshDatabase;

    private function picture(string $name): Media
    {
        return Media::query()->create(['disk' => 'public', 'mime' => 'image/jpeg', 'source_url' => "https://banners.example.test/{$name}.jpg", 'is_placeholder' => false, 'alt_en' => $name]);
    }

    #[Test]
    public function a_banner_needs_its_picture_before_it_can_be_published_and_then_reaches_the_website(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');

        $this->getJson('/api/v1/public/offers')->assertOk()->assertExactJson(['data' => []]);

        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/offer-banners', [
            'title_bn' => 'মুস্তাং ট্যুরে ৬% ছাড়', 'title_en' => '6% off the Mustang tour', 'link_url' => '/packages/nepal-mustang-adventure-tour-8-days-7-nights',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/offer-banners/{$created['id']}/publish")->assertUnprocessable()
            ->assertJsonPath('problems', ['Add the banner picture.']);
        $this->getJson('/api/v1/public/offers')->assertOk()->assertJsonCount(0, 'data');

        $picture = $this->picture('mustang-offer');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/offer-banners/{$created['id']}", [...$created, 'media_id' => $picture->id])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/offer-banners/{$created['id']}/publish")->assertOk();
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['offers']);

        $banner = $this->getJson('/api/v1/public/offers')->assertOk()->json('data.0');
        $this->assertSame(['bn' => 'মুস্তাং ট্যুরে ৬% ছাড়', 'en' => '6% off the Mustang tour'], $banner['title']);
        $this->assertSame('/packages/nepal-mustang-adventure-tour-8-days-7-nights', $banner['linkUrl']);
        $this->assertSame('https://banners.example.test/mustang-offer.jpg', $banner['image']['url']);
        $this->assertSame(['cms.offer_banner.created', 'cms.offer_banner.updated', 'cms.offer_banner.published'],
            AuditLog::query()->where('auditable_type', 'offer_banner')->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function banners_slide_in_the_order_staff_set_and_an_unpublished_one_drops_out(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');
        $ids = [];
        foreach (['Eid offer', 'Winter offer', 'Group discount'] as $title) {
            $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/offer-banners', ['title_bn' => $title, 'title_en' => $title, 'media_id' => $this->picture($title)->id])->assertCreated()->json('data.id');
            $this->actingAsApi($admin)->postJson("/api/v1/admin/offer-banners/{$id}/publish")->assertOk();
            $ids[$title] = $id;
        }

        $this->actingAsApi($admin)->putJson('/api/v1/admin/offer-banners/order', ['ids' => [$ids['Winter offer'], $ids['Eid offer'], $ids['Group discount']]])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/offer-banners/{$ids['Group discount']}/unpublish")->assertOk();

        $this->assertSame(['Winter offer', 'Eid offer'], array_column($this->getJson('/api/v1/public/offers')->assertOk()->json('data.*.title'), 'en'));
    }

    #[Test]
    public function a_banner_leads_somewhere_real_or_nowhere_at_all(): void
    {
        $admin = $this->staff('admin');
        $banner = ['title_bn' => 'অফার', 'title_en' => 'Offer'];

        foreach (['javascript:alert(1)', 'http://example.com/offer', 'packages/nepal', 'mailto:a@b.test'] as $bad) {
            $this->actingAsApi($admin)->postJson('/api/v1/admin/offer-banners', [...$banner, 'link_url' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('link_url');
        }
        foreach ([null, '/packages/nepal-mustang', 'https://wa.me/8801743939300'] as $good) {
            $this->actingAsApi($admin)->postJson('/api/v1/admin/offer-banners', [...$banner, 'link_url' => $good])->assertCreated();
        }
    }

    #[Test]
    public function a_picture_a_banner_shows_cannot_be_deleted_and_only_website_staff_may_edit_the_list(): void
    {
        $admin = $this->staff('admin');
        $picture = $this->picture('eid');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/offer-banners', ['title_bn' => 'ঈদ অফার', 'title_en' => 'Eid offer', 'media_id' => $picture->id])->assertCreated();

        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$picture->id}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

        foreach (['tour_operator', 'sales_agent', 'accountant'] as $role) {
            $this->actingAsApi($this->staff($role))->getJson('/api/v1/admin/offer-banners')->assertForbidden();
        }
    }
}
