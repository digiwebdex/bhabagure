<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\Destination;
use App\Models\Media;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CmsPackageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_package_starts_as_a_draft_that_the_website_cannot_see(): void
    {
        $operator = $this->staff('tour_operator');
        $destination = Destination::query()->create(['slug' => 'nepal', 'name_bn' => 'নেপাল', 'name_en' => 'Nepal']);

        $response = $this->actingAsApi($operator)->postJson('/api/v1/admin/packages', $this->payload($destination->id))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.regular_price', 30000)
            ->assertJsonPath('data.itinerary.0.title_en', 'DHAKA ➔ KATHMANDU')
            ->assertJsonPath('data.activities', ['Hiking', 'Boating']);

        $this->getJson('/api/v1/public/packages/pokhara-escape')->assertNotFound();
        $this->assertTrue(AuditLog::query()->where('action', 'cms.package.created')->where('auditable_id', $response->json('data.id'))->exists());
    }

    #[Test]
    public function publishing_lists_what_is_missing_then_succeeds_once_complete(): void
    {
        $operator = $this->staff('tour_operator');
        $destination = Destination::query()->create(['slug' => 'nepal', 'name_bn' => 'নেপাল', 'name_en' => 'Nepal']);
        $id = $this->actingAsApi($operator)->postJson('/api/v1/admin/packages', [...$this->payload($destination->id), 'title_bn' => ''])->json('data.id');

        $this->actingAsApi($operator)->postJson("/api/v1/admin/packages/{$id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_ready_to_publish')
            ->assertJsonCount(2, 'problems'); // Bangla title and a photo

        $this->actingAsApi($operator)->putJson("/api/v1/admin/packages/{$id}", $this->payload($destination->id))->assertOk();
        $media = Media::query()->create(['mime' => 'image/jpeg', 'source_url' => 'https://images.example.test/pokhara.jpg']);
        $this->actingAsApi($operator)->postJson("/api/v1/admin/packages/{$id}/images", ['media_id' => $media->id])->assertCreated()->assertJsonPath('data.0.is_cover', true);

        $this->actingAsApi($operator)->postJson("/api/v1/admin/packages/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson('/api/v1/public/packages/pokhara-escape')->assertOk()->assertJsonPath('data.title.bn', 'পোখারা এস্কেপ');
    }

    #[Test]
    public function a_package_without_a_price_or_duration_cannot_be_published(): void
    {
        $this->seed(ContentSeeder::class);
        $admin = $this->staff('admin');
        $package = TourPackage::query()->where('status', 'draft')->firstOrFail();
        $package->forceFill(['regular_price' => 0, 'sale_price' => null, 'duration_days' => 0])->save();

        $problems = $this->withHeader('X-Locale', 'en')->actingAsApi($admin)->postJson("/api/v1/admin/packages/{$package->id}/publish")
            ->assertUnprocessable()->json('problems');

        $this->assertContains('Set the price.', $problems);
        $this->assertContains('Set the duration in days.', $problems);
    }

    #[Test]
    public function an_edit_cannot_leave_a_live_package_unpublishable(): void
    {
        $this->seed(ContentSeeder::class);
        $admin = $this->staff('admin');
        $package = TourPackage::query()->published()->firstOrFail();

        $this->actingAsApi($admin)->putJson("/api/v1/admin/packages/{$package->id}", [...$this->payload($package->destination_id), 'code' => $package->code, 'slug' => $package->slug, 'itinerary' => []])
            ->assertUnprocessable()->assertJsonPath('code', 'not_ready_to_publish');

        // Rolled back: the itinerary is still there.
        $this->assertGreaterThan(0, $package->itineraryDays()->count());

        $images = $package->images()->get();
        foreach ($images->slice(1) as $image) {
            $this->actingAsApi($admin)->deleteJson("/api/v1/admin/packages/{$package->id}/images/{$image->id}")->assertOk();
        }
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/packages/{$package->id}/images/{$images->first()->id}")->assertUnprocessable();
    }

    #[Test]
    public function images_can_be_reordered_and_the_cover_moved(): void
    {
        $this->seed(ContentSeeder::class);
        $admin = $this->staff('admin');
        $package = TourPackage::query()->published()->firstOrFail();
        $ids = $package->images()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAsApi($admin)->putJson("/api/v1/admin/packages/{$package->id}/images/order", ['image_ids' => $reversed, 'cover_image_id' => $reversed[0]])
            ->assertOk()->assertJsonPath('data.0.id', $reversed[0])->assertJsonPath('data.0.is_cover', true);

        $this->actingAsApi($admin)->putJson("/api/v1/admin/packages/{$package->id}/images/order", ['image_ids' => [$ids[0]], 'cover_image_id' => $ids[0]])
            ->assertUnprocessable();
    }

    #[Test]
    public function saving_tells_the_website_which_cache_to_refresh(): void
    {
        config(['bhabaghure.web_revalidate_url' => 'https://web.example.test/api/revalidate', 'bhabaghure.revalidate_secret' => 'shh']);
        Http::fake(['web.example.test/*' => Http::response(['revalidated' => ['packages']])]);
        $this->seed(ContentSeeder::class);
        $package = TourPackage::query()->published()->firstOrFail();

        $this->actingAsApi($this->staff('admin'))->postJson("/api/v1/admin/packages/{$package->id}/unpublish")->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://web.example.test/api/revalidate'
            && $request->hasHeader('Authorization', 'Bearer shh')
            && $request['tags'] === ['packages', 'departures']);
        $this->getJson("/api/v1/public/packages/{$package->slug}")->assertNotFound();
    }

    #[Test]
    public function revalidation_waits_for_the_database_commit(): void
    {
        Bus::fake();
        $this->seed(ContentSeeder::class);
        $package = TourPackage::query()->published()->firstOrFail();

        $this->actingAsApi($this->staff('admin'))->postJson("/api/v1/admin/packages/{$package->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');

        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->afterCommit === true);
    }

    #[Test]
    public function the_sale_price_must_be_below_the_regular_price_and_money_has_two_decimals_at_most(): void
    {
        $destination = Destination::query()->create(['slug' => 'nepal', 'name_bn' => 'নেপাল', 'name_en' => 'Nepal']);

        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->postJson('/api/v1/admin/packages', [...$this->payload($destination->id), 'sale_price' => 30000, 'regular_price' => 30000])
            ->assertUnprocessable()->assertJsonValidationErrors('sale_price');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/packages', [...$this->payload($destination->id), 'regular_price' => 30000.123])
            ->assertUnprocessable()->assertJsonValidationErrors('regular_price');
    }

    private function payload(int $destinationId): array
    {
        return [
            'code' => 'Nepal 09',
            'slug' => 'pokhara-escape',
            'destination_id' => $destinationId,
            'title_en' => 'Pokhara Escape',
            'title_bn' => 'পোখারা এস্কেপ',
            'summary_en' => 'Return air ticket, 3-star hotels',
            'summary_bn' => 'রিটার্ন এয়ার টিকেট, ৩-স্টার হোটেল',
            'duration_days' => 4,
            'duration_nights' => 3,
            'regular_price' => 30000,
            'sale_price' => 27000,
            'includes_airfare' => true,
            'group_mode' => 'group',
            'min_pax' => null,
            'departure_mode' => 'any_date',
            'itinerary' => [
                ['day_number' => 2, 'title_en' => 'POKHARA', 'title_bn' => 'পোখারা', 'body_en' => 'Lakeside.', 'body_bn' => 'লেকসাইড।'],
                ['day_number' => 1, 'title_en' => 'DHAKA ➔ KATHMANDU', 'title_bn' => 'ঢাকা ➔ কাঠমান্ডু', 'body_en' => 'Arrival.', 'body_bn' => 'পৌঁছানো।'],
            ],
            'includes' => [['text_en' => 'Daily breakfast', 'text_bn' => 'প্রতিদিন ব্রেকফাস্ট']],
            'excludes' => [['text_en' => 'Lunch', 'text_bn' => 'লাঞ্চ']],
            'activities' => ['Hiking', 'Boating'],
            'trip_types' => ['Budget Travel'],
        ];
    }
}
