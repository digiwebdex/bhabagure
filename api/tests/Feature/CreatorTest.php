<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\CreatorVideo;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/travel-host.md: the travel host on the home page — a card each for their Facebook page and YouTube channel, and
 * the videos staff pick to show under them.
 */
class CreatorTest extends TestCase
{
    use RefreshDatabase;

    private function photo(string $name): Media
    {
        return Media::query()->create(['disk' => 'public', 'mime' => 'image/jpeg', 'source_url' => "https://images.example.test/{$name}.jpg", 'is_placeholder' => false, 'alt_en' => $name]);
    }

    /** @return array<string, mixed> */
    private function profile(array $override = []): array
    {
        return [
            'name_bn' => 'শিশির দেব', 'name_en' => 'Shishir Deb',
            'bio_bn' => 'বাংলাদেশের একজন ট্রাভেল ভ্লগার।', 'bio_en' => 'A travel vlogger from Bangladesh.',
            'facebook_url' => 'https://www.facebook.com/shishirdeb.traveller/?mibextid=wwXIfr', 'facebook_followers' => 1107339,
            'youtube_url' => 'https://youtube.com/@shishirdeb?si=IWQwGLPek8MzQRRi', 'youtube_subscribers' => 712000, 'youtube_video_count' => 295,
            ...$override,
        ];
    }

    #[Test]
    public function the_section_stays_hidden_until_a_profile_with_a_link_is_saved(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');

        $this->getJson('/api/v1/public/creator')->assertOk()->assertExactJson(['data' => ['profile' => null, 'videos' => []]]);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/creator')->assertOk()->assertJsonPath('data.name_en', '')->assertJsonPath('data.facebook_url', null);

        // A name alone links nowhere.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/creator', $this->profile(['facebook_url' => null, 'youtube_url' => '']))->assertUnprocessable()
            ->assertJsonValidationErrors(['facebook_url', 'youtube_url'])
            ->assertJsonPath('errors.facebook_url.0', 'Add the Facebook page or the YouTube channel link: a card with neither links nowhere.');
        $this->actingAsApi($admin)->putJson('/api/v1/admin/creator', $this->profile(['facebook_url' => 'https://www.instagram.com/shishirdeb', 'youtube_url' => 'https://www.youtube.com/watch?v=lI5NMGqg6xk', 'facebook_followers' => -1]))
            ->assertUnprocessable()->assertJsonValidationErrors(['facebook_url', 'youtube_url', 'facebook_followers']);
        $this->getJson('/api/v1/public/creator')->assertOk()->assertJsonPath('data.profile', null);
        Bus::assertNotDispatched(RevalidateWebsite::class);
    }

    #[Test]
    public function a_saved_profile_reaches_the_website_with_its_photos_and_without_the_tracking_on_shared_links(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');
        [$facebookPhoto, $youtubePhoto, $banner] = [$this->photo('fb-photo'), $this->photo('yt-photo'), $this->photo('yt-banner')];

        $saved = $this->actingAsApi($admin)->putJson('/api/v1/admin/creator', $this->profile([
            'facebook_photo_media_id' => $facebookPhoto->id, 'youtube_photo_media_id' => $youtubePhoto->id, 'youtube_cover_media_id' => $banner->id,
        ]))->assertOk()->json('data');

        // The form gets back what it edits, with each photo in full so it can show it.
        $this->assertSame('https://www.facebook.com/shishirdeb.traveller/', $saved['facebook_url']);
        $this->assertSame('https://youtube.com/@shishirdeb', $saved['youtube_url']);
        $this->assertSame($banner->id, $saved['youtube_cover']['id']);
        $this->assertNull($saved['facebook_cover']);
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['creator']);
        $audit = AuditLog::query()->where('action', 'cms.creator.updated')->sole();
        $this->assertSame([$admin->id, 'creator', 'https://www.facebook.com/shishirdeb.traveller/'], [$audit->actor_id, $audit->changes['key'], $audit->changes['value']['facebook']['url']]);

        $profile = $this->getJson('/api/v1/public/creator')->assertOk()->json('data.profile');
        $this->assertSame(['bn' => 'শিশির দেব', 'en' => 'Shishir Deb'], $profile['name']);
        $this->assertSame(['bn' => 'বাংলাদেশের একজন ট্রাভেল ভ্লগার।', 'en' => 'A travel vlogger from Bangladesh.'], $profile['bio']);
        $this->assertSame(['url' => 'https://www.facebook.com/shishirdeb.traveller/', 'followers' => 1107339], array_intersect_key($profile['facebook'], array_flip(['url', 'followers'])));
        $this->assertSame('https://images.example.test/fb-photo.jpg', $profile['facebook']['photo']['url']);
        $this->assertNull($profile['facebook']['cover']);
        $this->assertSame([712000, 295, 'https://images.example.test/yt-banner.jpg'], [$profile['youtube']['subscribers'], $profile['youtube']['videoCount'], $profile['youtube']['cover']['url']]);

        // One card is enough; a bio in one language shows in both.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/creator', $this->profile(['facebook_url' => '', 'bio_bn' => '', 'youtube_url' => 'https://www.youtube.com/@shishirdeb']))->assertOk();
        $profile = $this->getJson('/api/v1/public/creator')->json('data.profile');
        $this->assertNull($profile['facebook']);
        $this->assertSame(['bn' => 'A travel vlogger from Bangladesh.', 'en' => 'A travel vlogger from Bangladesh.'], $profile['bio']);
    }

    #[Test]
    public function a_facebook_profile_known_only_by_number_keeps_its_id(): void
    {
        $this->actingAsApi($this->staff('admin'))->putJson('/api/v1/admin/creator', $this->profile(['facebook_url' => 'https://m.facebook.com/profile.php?id=100064&mibextid=abc']))->assertOk()
            ->assertJsonPath('data.facebook_url', 'https://m.facebook.com/profile.php?id=100064');
    }

    #[Test]
    public function a_photo_the_profile_shows_cannot_be_deleted_from_the_media_library(): void
    {
        $admin = $this->staff('admin');
        $photo = $this->photo('fb-photo');
        $spare = $this->photo('spare');
        $this->actingAsApi($admin)->putJson('/api/v1/admin/creator', $this->profile(['facebook_photo_media_id' => $photo->id]))->assertOk();

        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$photo->id}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$spare->id}")->assertOk();
    }

    #[Test]
    public function a_video_link_of_any_shape_becomes_one_video_and_the_same_video_cannot_be_added_twice(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');

        foreach ([
            'https://www.youtube.com/watch?v=lI5NMGqg6xk&t=42s' => 'lI5NMGqg6xk',
            'https://youtu.be/VItbnk4mTO4?si=tracking' => 'VItbnk4mTO4',
            'https://m.youtube.com/shorts/6EY_aaulMfM' => '6EY_aaulMfM',
            'https://www.youtube.com/watch?feature=share&v=KE6g2D_KIi0' => 'KE6g2D_KIi0',
        ] as $url => $id) {
            $this->assertSame($id, CreatorVideo::idFromUrl($url), $url);
        }
        foreach (['https://www.youtube.com/@shishirdeb', 'https://vimeo.com/123', 'http://youtu.be/lI5NMGqg6xk', 'https://youtu.be/short'] as $bad) {
            $this->assertNull(CreatorVideo::idFromUrl($bad), $bad);
        }

        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/creator-videos', ['url' => 'https://youtu.be/lI5NMGqg6xk?si=x', 'title_bn' => '৩ দেশ ঘুরে আসলাম'])
            ->assertCreated()->assertJsonPath('data.youtube_id', 'lI5NMGqg6xk')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=lI5NMGqg6xk')
            ->assertJsonPath('data.thumbnail_url', 'https://i.ytimg.com/vi/lI5NMGqg6xk/hqdefault.jpg')->json('data');

        $this->actingAsApi($admin)->postJson('/api/v1/admin/creator-videos', ['url' => 'https://www.youtube.com/watch?v=lI5NMGqg6xk', 'title_en' => 'Again'])
            ->assertUnprocessable()->assertJsonPath('errors.url.0', 'This video is already on the list.');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/creator-videos', ['url' => 'https://www.youtube.com/@shishirdeb'])
            ->assertUnprocessable()->assertJsonValidationErrors(['url', 'title_bn', 'title_en']);
        // Saving a video under its own link again is not a duplicate of itself.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/creator-videos/{$created['id']}", [...$created, 'title_en' => 'Three countries for 1.2 lakh taka'])->assertOk()
            ->assertJsonPath('data.title_en', 'Three countries for 1.2 lakh taka');
    }

    #[Test]
    public function videos_are_hidden_until_published_and_come_back_in_the_order_staff_set(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');
        $ids = [];
        foreach (['lI5NMGqg6xk' => 'Three countries', '6EY_aaulMfM' => 'Mount Bromo', 'VItbnk4mTO4' => 'Philippines'] as $youtubeId => $title) {
            $ids[$youtubeId] = $this->actingAsApi($admin)->postJson('/api/v1/admin/creator-videos', ['url' => "https://youtu.be/{$youtubeId}", 'title_en' => $title])->assertCreated()->json('data.id');
        }

        $this->getJson('/api/v1/public/creator')->assertOk()->assertJsonPath('data.videos', []);
        foreach ($ids as $id) {
            $this->actingAsApi($admin)->postJson("/api/v1/admin/creator-videos/{$id}/publish")->assertOk();
        }
        $this->actingAsApi($admin)->postJson("/api/v1/admin/creator-videos/{$ids['6EY_aaulMfM']}/unpublish")->assertOk();
        $this->actingAsApi($admin)->putJson('/api/v1/admin/creator-videos/order', ['ids' => [$ids['VItbnk4mTO4'], $ids['lI5NMGqg6xk'], $ids['6EY_aaulMfM']]])->assertOk();

        $this->getJson('/api/v1/public/creator')->assertOk()->assertJsonPath('data.videos', [
            ['youtubeId' => 'VItbnk4mTO4', 'url' => 'https://www.youtube.com/watch?v=VItbnk4mTO4', 'title' => ['bn' => 'Philippines', 'en' => 'Philippines'], 'thumbnail' => 'https://i.ytimg.com/vi/VItbnk4mTO4/hqdefault.jpg'],
            ['youtubeId' => 'lI5NMGqg6xk', 'url' => 'https://www.youtube.com/watch?v=lI5NMGqg6xk', 'title' => ['bn' => 'Three countries', 'en' => 'Three countries'], 'thumbnail' => 'https://i.ytimg.com/vi/lI5NMGqg6xk/hqdefault.jpg'],
        ]);
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['creator']);
        $this->assertSame(3, AuditLog::query()->where('action', 'cms.creator_video.published')->count());
    }

    #[Test]
    public function only_staff_who_manage_the_website_edit_the_travel_host(): void
    {
        foreach (['tour_operator', 'sales_agent', 'accountant'] as $role) {
            $staff = $this->staff($role);
            $this->actingAsApi($staff)->getJson('/api/v1/admin/creator')->assertForbidden();
            $this->actingAsApi($staff)->putJson('/api/v1/admin/creator', $this->profile())->assertForbidden();
            $this->actingAsApi($staff)->getJson('/api/v1/admin/creator-videos')->assertForbidden();
        }
    }
}
