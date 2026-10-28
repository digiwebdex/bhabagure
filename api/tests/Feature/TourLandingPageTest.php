<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Media;
use App\Models\PackageDeparture;
use App\Models\Review;
use App\Models\TourLandingPage;
use App\Models\TourPackage;
use App\Models\TourPhoto;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Website → Tour landing pages: a group tour's own page (docs/tour-landing-pages.md). */
class TourLandingPageTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private TourPackage $package;

    private PackageDeparture $departure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
        $this->package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $this->package->update(['trip_type' => TourPackage::GROUP_FIXED]);
        $this->departure = PackageDeparture::query()->create([
            'tour_package_id' => $this->package->id, 'departs_on' => now('Asia/Dhaka')->addDays(40)->toDateString(),
            'returns_on' => now('Asia/Dhaka')->addDays(46)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled',
        ]);
    }

    #[Test]
    public function staff_make_a_draft_page_for_a_group_tour_with_photos_beside_its_days(): void
    {
        $admin = $this->staff('admin');
        $hero = $this->media('hero');
        [$a, $b] = [$this->media('day-1'), $this->media('day-2')];

        $page = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang-valley-trip',
            'tour_package_id' => $this->package->id,
            'title_en' => '  ',
            'hero_media_id' => $hero->id,
            'countdown_departure_id' => $this->departure->id,
            'promote_on_home' => true,
            'day_photos' => [['day_number' => 1, 'media_id' => $a->id], ['day_number' => 1, 'media_id' => $b->id], ['day_number' => 3, 'media_id' => $a->id]],
        ])->assertCreated()->json('data');

        $this->assertSame(['draft', null, true, $this->package->id], [$page['status'], $page['title_en'], $page['promote_on_home'], $page['package']['id']]);
        $this->assertSame([[1, $a->id], [1, $b->id], [3, $a->id]], array_map(fn ($p) => [$p['day_number'], $p['media_id']], $page['day_photos']));
        // What it reads from the package, shown in the editor: the days and the departures.
        $this->assertSame($this->package->itineraryDays()->count(), count($page['package']['days']));
        $this->assertSame($this->departure->id, $page['package']['departures'][0]['id']);
        $this->assertTrue(AuditLog::query()->where('action', 'cms.tour_landing_page.created')->exists());
        // The package's editor links to it.
        $this->actingAsApi($admin)->getJson("/api/v1/admin/packages/{$this->package->id}")->assertOk()->assertJsonPath('data.landing_page.slug', 'mustang-valley-trip');

        // A draft is not on the website.
        $this->getJson('/api/v1/public/tour-pages')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function only_a_group_tour_gets_one_page_and_its_own_departure_counts_down(): void
    {
        $admin = $this->staff('admin');
        $customized = TourPackage::query()->where('trip_type', 'customized')->firstOrFail();
        $other = PackageDeparture::query()->create(['tour_package_id' => $customized->id, 'departs_on' => now()->addDays(20)->toDateString(), 'seats_total' => 5, 'status' => 'scheduled']);

        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', ['slug' => 'a-trip', 'tour_package_id' => $customized->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['tour_package_id']);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', ['slug' => 'Not A Slug', 'tour_package_id' => $this->package->id, 'countdown_departure_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug', 'countdown_departure_id']);
        $media = $this->media('x');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang', 'tour_package_id' => $this->package->id,
            'day_photos' => array_fill(0, 4, ['day_number' => 2, 'media_id' => $media->id]),
        ])->assertUnprocessable()->assertJsonValidationErrors(['day_photos']);

        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', ['slug' => 'mustang', 'tour_package_id' => $this->package->id])->assertCreated();
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', ['slug' => 'mustang-2', 'tour_package_id' => $this->package->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['tour_package_id']);
    }

    #[Test]
    public function a_published_page_reaches_the_website_with_its_days_photos_and_the_gallerys_memories(): void
    {
        $admin = $this->staff('admin');
        $this->package->update(['status' => 'draft']);
        $dayPhoto = $this->media('day');
        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang-valley-trip', 'tour_package_id' => $this->package->id, 'intro_en' => 'Our own words for this page.',
            'countdown_departure_id' => $this->departure->id, 'day_photos' => [['day_number' => 2, 'media_id' => $dayPhoto->id]],
        ])->json('data.id');

        // Its tour must be live first.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-landing-pages/{$id}/publish")->assertUnprocessable()->assertJsonPath('code', 'not_ready_to_publish');
        $this->package->update(['status' => 'published']);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-landing-pages/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $linked = TourPhoto::query()->create(['media_id' => $this->media('linked')->id, 'caption_en' => 'Mustang 2025', 'caption_bn' => 'মুস্তাং ২০২৫', 'tour_package_id' => $this->package->id, 'status' => 'published', 'sort_order' => 2]);
        TourPhoto::query()->create(['media_id' => $this->media('unlinked')->id, 'caption_en' => 'Dhaka airport', 'caption_bn' => 'ঢাকা', 'status' => 'published', 'sort_order' => 1]);
        TourPhoto::query()->create(['media_id' => $this->media('draft')->id, 'caption_en' => 'Not yet', 'caption_bn' => 'না', 'status' => 'draft', 'sort_order' => 0]);
        // Already beside day 2: not shown again among the memories.
        TourPhoto::query()->create(['media_id' => $dayPhoto->id, 'caption_en' => 'Same photo', 'caption_bn' => 'একই ছবি', 'status' => 'published', 'sort_order' => 3]);

        $page = $this->getJson('/api/v1/public/tour-pages')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['mustang-valley-trip', self::MUSTANG, null, $this->departure->departs_on->toDateString(), false], [$page['slug'], $page['packageSlug'], $page['title'], $page['countdownDepartsOn'], $page['promote']]);
        $this->assertSame(['bn' => 'Our own words for this page.', 'en' => 'Our own words for this page.'], $page['intro']);
        $this->assertSame([2], array_column($page['days'], 'day'));
        $this->assertCount(1, $page['days'][0]['photos']);
        // The gallery's published photos, this tour's first; each with its caption.
        $this->assertSame(['Mustang 2025', 'Dhaka airport'], array_column(array_column($page['memories'], 'caption'), 'en'));
        $this->assertSame(self::MUSTANG, $page['memories'][0]['package']['slug']);
        $this->assertSame($linked->tripMonthValue(), $page['memories'][0]['month']);

        // A cancelled countdown date: the website counts down to the next scheduled one instead.
        $this->departure->update(['status' => 'cancelled']);
        $this->getJson('/api/v1/public/tour-pages')->assertOk()->assertJsonPath('data.0.countdownDepartsOn', null);

        // Its tour taken off the website: the page goes too.
        $this->package->update(['status' => 'draft']);
        $this->getJson('/api/v1/public/tour-pages')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_photo_a_page_uses_stays_in_the_media_library(): void
    {
        $admin = $this->staff('admin');
        [$hero, $day] = [$this->media('hero'), $this->media('day')];
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang', 'tour_package_id' => $this->package->id, 'hero_media_id' => $hero->id, 'day_photos' => [['day_number' => 1, 'media_id' => $day->id]],
        ])->assertCreated();

        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$hero->id}")->assertStatus(409);
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$day->id}")->assertStatus(409);
    }

    #[Test]
    public function a_page_shows_its_tours_reviews_featured_first_and_its_videos_by_section(): void
    {
        $admin = $this->staff('admin');
        Review::query()->delete();
        $review = fn (string $name, string $status = 'published', ?int $package = null, int $order = 0) => Review::query()->create([
            'quote_bn' => "{$name} এর কথা", 'quote_en' => "{$name} says", 'reviewer_name' => $name, 'rating' => 5,
            'tour_package_id' => $package ?? $this->package->id, 'status' => $status, 'sort_order' => $order,
        ]);
        [$first, $second, $third] = [$review('Rina', order: 1), $review('Karim', order: 2), $review('Tania', order: 3)];
        $draft = $review('Draft', 'draft');
        $otherTour = $review('Elsewhere', package: TourPackage::query()->whereKeyNot($this->package->id)->value('id'));
        $thumb = $this->media('thumb');

        // Only published reviews may be featured; only YouTube and Facebook links are taken.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang', 'tour_package_id' => $this->package->id, 'featured_review_ids' => [$draft->id],
            'videos' => [['section' => 'guest', 'url' => 'https://vimeo.com/12345'], ['section' => 'other', 'url' => 'https://youtu.be/dQw4w9WgXcQ']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['featured_review_ids.0', 'videos.0.url', 'videos.1.section']);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang', 'tour_package_id' => $this->package->id,
            'videos' => array_fill(0, 13, ['section' => 'vlog', 'url' => 'https://youtu.be/dQw4w9WgXcQ']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['videos']);

        $page = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-landing-pages', [
            'slug' => 'mustang', 'tour_package_id' => $this->package->id,
            // A review of another tour may be featured too: staff chose it.
            'featured_review_ids' => [$third->id, $otherTour->id],
            'videos' => [
                ['section' => 'vlog', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'title_en' => '  Our Mustang vlog  '],
                ['section' => 'guest', 'url' => 'https://www.facebook.com/watch/?v=1234567890', 'title_bn' => 'রিনার কথা', 'thumbnail_media_id' => $thumb->id],
                ['section' => 'guest', 'url' => 'https://youtu.be/abcdefghijk'],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame([$third->id, $otherTour->id], $page['featured_review_ids']);
        $this->assertSame([['guest', 'facebook', null], ['guest', 'youtube', 'abcdefghijk'], ['vlog', 'youtube', 'dQw4w9WgXcQ']], array_map(fn ($v) => [$v['section'], $v['provider'], $v['youtube_id']], $page['videos']));
        $this->assertSame('Our Mustang vlog', $page['videos'][2]['title_en']);
        // The editor lists every published review to pick from, this tour's first.
        $choices = $page['review_choices'];
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], array_column(array_slice($choices, 0, 3), 'id'));
        $this->assertSame([[true, true, true, false], $otherTour->id], [array_column($choices, 'this_tour'), $choices[3]['id']]);
        // A thumbnail in use stays in the media library.
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$thumb->id}")->assertStatus(409);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-landing-pages/{$page['id']}/publish")->assertOk();
        $public = $this->getJson('/api/v1/public/tour-pages')->assertOk()->json('data.0');
        // The featured first, in the order chosen; then the tour's other published reviews in their usual order.
        $this->assertSame(['Tania', 'Elsewhere', 'Rina', 'Karim'], array_column($public['reviews'], 'reviewerName'));
        $this->assertSame(['guest', 'facebook', null, 'রিনার কথা'], [$public['videos'][0]['section'], $public['videos'][0]['provider'], $public['videos'][0]['youtubeId'], $public['videos'][0]['title']['bn']]);
        $this->assertNotNull($public['videos'][0]['thumbnail']);
        $this->assertSame('dQw4w9WgXcQ', $public['videos'][2]['youtubeId']);

        // A featured review taken off the website leaves the page; emptied lists empty the sections.
        $third->update(['status' => 'draft']);
        $this->assertSame(['Elsewhere', 'Rina', 'Karim'], array_column($this->getJson('/api/v1/public/tour-pages')->json('data.0.reviews'), 'reviewerName'));
        $this->actingAsApi($admin)->putJson("/api/v1/admin/tour-landing-pages/{$page['id']}", ['slug' => 'mustang', 'tour_package_id' => $this->package->id, 'featured_review_ids' => [], 'videos' => []])->assertOk();
        Review::query()->whereKey([$first->id, $second->id])->update(['status' => 'draft']);
        $public = $this->getJson('/api/v1/public/tour-pages')->json('data.0');
        $this->assertSame([[], []], [$public['reviews'], $public['videos']]);
    }

    #[Test]
    public function only_website_staff_manage_landing_pages(): void
    {
        $this->getJson('/api/v1/admin/tour-landing-pages')->assertUnauthorized();
        $this->actingAsApi($this->staff('accountant'))->getJson('/api/v1/admin/tour-landing-pages')->assertForbidden();
        $this->assertSame(0, TourLandingPage::query()->count());
    }

    private function media(string $name): Media
    {
        return Media::query()->create(['disk' => 'public', 'mime' => 'image/jpeg', 'source_url' => "https://photos.example.test/{$name}.jpg", 'is_placeholder' => false, 'width' => 1600, 'height' => 1067]);
    }
}
