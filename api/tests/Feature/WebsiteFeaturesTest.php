<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\BlogPost;
use App\Models\Review;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/website-features.md: switches in Site settings that hide a website feature from the public, and only that. */
class WebsiteFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_ON = [
        'blog' => true, 'team' => true, 'reviews' => true, 'gallery' => true, 'visas' => true,
        'creator' => true, 'partners' => true, 'offers' => true, 'tourPhotos' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function every_feature_is_on_until_switched_off_and_a_switch_reaches_the_website_at_once(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');

        // Nothing saved yet: the website is as it was.
        $this->getJson('/api/v1/public/settings')->assertOk()->assertJsonPath('data.features', self::ALL_ON);

        // Every switch is needed, each a true or false.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/features', ['value' => ['blog' => false]])
            ->assertUnprocessable()->assertJsonValidationErrors(['value.team', 'value.tourPhotos']);
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/features', ['value' => ['blog' => 'maybe'] + self::ALL_ON])
            ->assertUnprocessable()->assertJsonValidationErrors('value.blog');

        // Off: saved as a real boolean, unknown keys dropped, and the website told to refresh.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/features', ['value' => ['blog' => '0', 'reviews' => false, 'extra' => true] + self::ALL_ON])
            ->assertOk()->assertJsonPath('data.features', array_merge(self::ALL_ON, ['blog' => false, 'reviews' => false]));
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => in_array('settings', $job->tags, true));
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.features.blog', false)->assertJsonPath('data.features.team', true);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/settings')->assertJsonPath('data.features.reviews', false);

        // Only staff who edit Site settings change them.
        $this->actingAsApi($this->staff('sales_agent'))->putJson('/api/v1/admin/settings/features', ['value' => self::ALL_ON])->assertForbidden();
    }

    #[Test]
    public function switching_reviews_off_stops_new_reviews_and_switching_it_on_takes_them_again_with_nothing_lost(): void
    {
        $admin = $this->staff('admin');
        $posts = BlogPost::query()->count();
        $send = fn () => $this->postJson('/api/v1/public/reviews', [
            'name' => 'Tanvir Hasan', 'phone' => '01711-000321', 'trip' => 'Cox’s Bazar with family', 'travelled_month' => now('Asia/Dhaka')->subMonth()->format('Y-m'),
            'rating' => 5, 'review' => 'Every hotel was clean and the guide was patient with our children all week.', 'locale' => 'en',
        ]);

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/features', ['value' => ['reviews' => false, 'blog' => false] + self::ALL_ON])->assertOk();
        $send()->assertStatus(409)->assertJsonPath('code', 'reviews_off');
        $this->assertSame(0, Review::query()->count());

        // Off is not delete: the content stays, and the admin still manages it.
        $this->assertSame($posts, BlogPost::query()->count());
        $this->actingAsApi($admin)->getJson('/api/v1/admin/posts')->assertOk();

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/features', ['value' => self::ALL_ON])->assertOk();
        $send()->assertAccepted();
        $this->assertSame(1, Review::query()->count());
    }
}
