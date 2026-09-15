<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\BlogPost;
use App\Models\Booking;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website runs on packages/content-seed today and on this API after the switch. After seeding, the API
 * must return the same content in the same shape (web/src/lib/content/types.ts), or the switch changes the site.
 */
class PublicContentContractTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_FIELDS = [
        'code', 'slug', 'wpTripId', 'destination', 'status', 'title', 'summary', 'durationDays', 'durationNights',
        'regularPrice', 'salePrice', 'includesAirfare', 'groupMode', 'minPax', 'departureMode', 'itinerary',
        'includes', 'excludes', 'activities', 'tripTypes',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function packages_match_the_seed_and_drafts_are_invisible(): void
    {
        $seed = collect($this->seedFile('packages.json'))->where('status', 'published')->values();
        $api = collect($this->getJson('/api/v1/public/packages')->assertOk()->json('data'));

        $this->assertSame($seed->pluck('code')->all(), $api->pluck('code')->all());

        foreach ($seed as $index => $expected) {
            $actual = $api[$index];
            foreach (self::PACKAGE_FIELDS as $field) {
                $this->assertEquals($expected[$field], $actual[$field], "{$expected['code']}: {$field}");
            }
            foreach ($expected['images'] as $position => $image) {
                foreach (['url', 'alt', 'credit', 'creditUrl', 'isPlaceholder'] as $field) {
                    $this->assertEquals($image[$field] ?? null, $actual['images'][$position][$field], "{$expected['code']}: images[{$position}].{$field}");
                }
            }
        }

        $draft = collect($this->seedFile('packages.json'))->firstWhere('status', 'draft');
        $this->getJson("/api/v1/public/packages/{$draft['slug']}")->assertNotFound();
        $this->getJson("/api/v1/public/packages/{$seed[0]['slug']}")->assertOk()->assertJsonPath('data.code', $seed[0]['code']);
    }

    #[Test]
    public function destinations_blog_team_pricing_and_settings_match_the_seed(): void
    {
        $this->assertEquals($this->seedFile('destinations.json'), $this->getJson('/api/v1/public/destinations')->json('data'));

        $blog = $this->seedFile('posts.json');
        $api = $this->getJson('/api/v1/public/posts')->assertOk();
        $this->assertEquals($blog['categories'], $api->json('data.categories'));
        $expectedPosts = collect($blog['posts'])->where('status', 'published')->sortByDesc('publishedAt')->values();
        foreach ($expectedPosts as $index => $post) {
            foreach (['slug', 'category', 'publishedAt', 'title', 'excerpt', 'author', 'readingMinutes', 'status'] as $field) {
                $this->assertEquals($post[$field], $api->json("data.posts.{$index}.{$field}"), "{$post['slug']}: {$field}");
            }
            // The sanitiser writes ' as &#039; — same rendered HTML. Tags must survive unchanged.
            foreach (['bn', 'en'] as $locale) {
                $this->assertSame(
                    html_entity_decode($post['body'][$locale], ENT_QUOTES | ENT_HTML5),
                    html_entity_decode($api->json("data.posts.{$index}.body.{$locale}"), ENT_QUOTES | ENT_HTML5),
                    "{$post['slug']}: body.{$locale}",
                );
            }
        }

        $team = $this->getJson('/api/v1/public/team')->json('data');
        foreach ($this->seedFile('team.json') as $index => $member) {
            foreach (['employeeCode', 'name', 'role', 'photo', 'sortOrder', 'isVisible'] as $field) {
                $this->assertEquals($member[$field], $team[$index][$field], "team[{$index}].{$field}");
            }
        }

        // onlineCheckout comes from the server's SSLCommerz configuration, not the seed (the test environment's fake gateway: on).
        $this->assertEquals($this->seedFile('pricing.json') + ['onlineCheckout' => true], $this->getJson('/api/v1/public/pricing')->json('data'));
        $this->assertEquals($this->seedFile('settings.json'), $this->getJson('/api/v1/public/settings')->json('data'));
    }

    #[Test]
    public function unpublished_and_scheduled_posts_stay_hidden(): void
    {
        $post = BlogPost::query()->first();

        $post->forceFill(['published_at' => now()->addDay()])->save();
        $this->getJson("/api/v1/public/posts/{$post->slug}")->assertNotFound();

        $post->forceFill(['published_at' => now()->subMinute(), 'status' => ContentStatus::Draft])->save();
        $this->getJson("/api/v1/public/posts/{$post->slug}")->assertNotFound();
        $this->assertNotContains($post->slug, collect($this->getJson('/api/v1/public/posts')->json('data.posts'))->pluck('slug'));
    }

    #[Test]
    public function reviews_and_departures_are_empty_until_real_items_are_published_and_the_gallery_holds_only_the_pages_reels(): void
    {
        $this->getJson('/api/v1/public/reviews')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/public/departures')->assertOk()->assertExactJson(['data' => []]);
        // Visa services come only from Admin → Visa services: no country, price or requirement is invented.
        $this->getJson('/api/v1/public/visas')->assertOk()->assertExactJson(['data' => []]);
        // The four reels from the company's Facebook page (a migration); none of the demo seed's illustrative photos.
        $gallery = collect($this->getJson('/api/v1/public/gallery')->assertOk()->json('data'));
        $this->assertCount(4, $gallery);
        $this->assertSame(['reel'], $gallery->pluck('kind')->unique()->values()->all());
    }

    #[Test]
    public function departures_show_seats_held_by_confirmed_bookings_only(): void
    {
        $package = TourPackage::query()->published()->firstOrFail();
        $departure = PackageDeparture::query()->create([
            'tour_package_id' => $package->id, 'departs_on' => now()->addMonth()->toDateString(), 'seats_total' => 20, 'is_guaranteed' => true,
        ]);
        PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => now()->subDay()->toDateString(), 'seats_total' => 20]);

        $customer = $this->customer();
        foreach (['confirmed' => 4, 'completed' => 2, 'inquiry' => 5, 'cancelled' => 3] as $status => $pax) {
            // Status is not mass-assignable (BookingStateMachine owns it); a fixture sets it directly.
            (new Booking)->forceFill([
                'reference' => "BH-TEST-{$status}", 'customer_id' => $customer->id, 'tour_package_id' => $package->id, 'departure_id' => $departure->id,
                'package_title_en' => $package->title_en, 'pax_count' => $pax, 'list_price' => 1000, 'unit_price' => 1000, 'subtotal_amount' => 1000 * $pax,
                'total_amount' => 1000 * $pax, 'source' => 'walk_in', 'status' => $status,
            ])->save();
        }

        $this->getJson('/api/v1/public/departures')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.packageCode', $package->code)
            ->assertJsonPath('data.0.seatsTotal', 20)
            ->assertJsonPath('data.0.seatsBooked', 6)
            ->assertJsonPath('data.0.isGuaranteed', true);
    }

    #[Test]
    public function money_is_a_json_number_never_a_formatted_string(): void
    {
        $package = $this->getJson('/api/v1/public/packages')->json('data.0');

        $this->assertIsInt($package['regularPrice']);
        $this->assertIsInt($package['salePrice']);
    }

    #[Test]
    public function reseeding_never_overwrites_cms_edits(): void
    {
        $package = TourPackage::query()->where('code', 'Nepal 01')->firstOrFail();
        $package->update(['title_bn' => 'CMS-এ সম্পাদিত শিরোনাম', 'regular_price' => 81000]);

        $this->seed(ContentSeeder::class);

        $this->assertSame('CMS-এ সম্পাদিত শিরোনাম', $package->fresh()->title_bn);
        $this->assertSame('81000.00', $package->fresh()->regular_price);
        $this->assertSame(6, TourPackage::query()->count());
    }

    private function seedFile(string $name): array
    {
        return json_decode(file_get_contents(config('bhabaghure.content_seed_path').'/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }
}
