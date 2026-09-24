<?php

namespace Tests\Feature;

use App\Enums\PackageStatus;
use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/group-tour-gallery.md: the group tour gallery on the home page. */
class TourPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private function picture(string $name): Media
    {
        return Media::query()->create(['disk' => 'public', 'mime' => 'image/jpeg', 'source_url' => "https://photos.example.test/{$name}.jpg", 'is_placeholder' => false, 'alt_en' => $name]);
    }

    private function mustang(): TourPackage
    {
        $this->seed(ContentSeeder::class);

        return TourPackage::query()->where('slug', self::MUSTANG)->firstOrFail();
    }

    #[Test]
    public function a_photo_needs_its_picture_before_it_can_be_published_and_then_reaches_the_website_with_its_trip(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $mustang = $this->mustang();
        $admin = $this->staff('admin');

        $this->getJson('/api/v1/public/tour-photos')->assertOk()->assertExactJson(['data' => []]);

        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', [
            'caption_bn' => 'মুস্তাং, নেপাল', 'caption_en' => 'Mustang, Nepal', 'trip_month' => '2026-09', 'tour_package_id' => $mustang->id,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.trip_month', '2026-09')
            ->assertJsonPath('data.package_title', $mustang->title_en)->json('data');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-photos/{$created['id']}/publish")->assertUnprocessable()
            ->assertJsonPath('problems', ['Add the photo.']);
        $this->getJson('/api/v1/public/tour-photos')->assertOk()->assertJsonCount(0, 'data');

        $picture = $this->picture('mustang-group');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/tour-photos/{$created['id']}", [...$created, 'media_id' => $picture->id])->assertOk()
            ->assertJsonPath('data.trip_month', '2026-09');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-photos/{$created['id']}/publish")->assertOk();
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['tour-photos']);

        $photo = $this->getJson('/api/v1/public/tour-photos')->assertOk()->json('data.0');
        $this->assertSame(['bn' => 'মুস্তাং, নেপাল', 'en' => 'Mustang, Nepal'], $photo['caption']);
        $this->assertSame('2026-09', $photo['month']);
        $this->assertSame('https://photos.example.test/mustang-group.jpg', $photo['image']['url']);
        $this->assertSame(['slug' => self::MUSTANG, 'title' => $mustang->localized('title')], $photo['package']);
        $this->assertSame(['cms.tour_photo.created', 'cms.tour_photo.updated', 'cms.tour_photo.published'],
            AuditLog::query()->where('auditable_type', 'tour_photo')->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function see_this_tour_shows_only_while_the_package_is_on_the_website(): void
    {
        $mustang = $this->mustang();
        $admin = $this->staff('admin');
        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', [
            'caption_bn' => 'মার্ফা, মুস্তাং', 'caption_en' => 'Marpha, Mustang', 'media_id' => $this->picture('marpha')->id, 'tour_package_id' => $mustang->id,
        ])->assertCreated()->assertJsonPath('data.trip_month', null)->json('data.id');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-photos/{$id}/publish")->assertOk();

        $public = fn () => $this->getJson('/api/v1/public/tour-photos')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(self::MUSTANG, $public()['package']['slug']);
        $this->assertNull($public()['month']);

        // The package taken off the website: the photo stays, its link goes.
        $mustang->forceFill(['status' => PackageStatus::Draft])->save();
        $this->assertNull($public()['package']);
        $mustang->forceFill(['status' => PackageStatus::Published])->save();
        $this->assertSame(self::MUSTANG, $public()['package']['slug']);

        // Deleted: no link, and it can't be chosen again.
        $mustang->delete();
        $this->assertNull($public()['package']);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', ['caption_bn' => 'মুস্তাং', 'caption_en' => 'Mustang', 'tour_package_id' => $mustang->id])
            ->assertUnprocessable()->assertJsonValidationErrors('tour_package_id');
    }

    #[Test]
    public function photos_show_in_the_order_staff_set_and_an_unpublished_one_drops_out(): void
    {
        $admin = $this->staff('admin');
        $ids = [];
        foreach (['Mustang', 'Phi Phi', 'Kathmandu'] as $caption) {
            $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', ['caption_bn' => $caption, 'caption_en' => $caption, 'media_id' => $this->picture($caption)->id])->assertCreated()->json('data.id');
            $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-photos/{$id}/publish")->assertOk();
            $ids[$caption] = $id;
        }

        $this->actingAsApi($admin)->putJson('/api/v1/admin/tour-photos/order', ['ids' => [$ids['Phi Phi'], $ids['Mustang'], $ids['Kathmandu']]])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/tour-photos/{$ids['Kathmandu']}/unpublish")->assertOk();

        $this->assertSame(['Phi Phi', 'Mustang'], array_column($this->getJson('/api/v1/public/tour-photos')->assertOk()->json('data.*.caption'), 'en'));
    }

    #[Test]
    public function a_photo_says_what_trip_it_was_and_the_month_is_a_month(): void
    {
        $admin = $this->staff('admin');
        $photo = ['caption_bn' => 'মুস্তাং', 'caption_en' => 'Mustang'];

        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', ['caption_bn' => '', 'caption_en' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['caption_bn', 'caption_en']);
        foreach (['2026-13', 'September 2026', '2026-09-15', '26-09'] as $bad) {
            $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', [...$photo, 'trip_month' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('trip_month');
        }
        foreach (['2026-09' => '2026-09', '2025-12' => '2025-12', '' => null] as $good => $stored) {
            $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', [...$photo, 'trip_month' => $good === '' ? null : $good])
                ->assertCreated()->assertJsonPath('data.trip_month', $stored);
        }

        // Saving without the month field leaves the month as it was.
        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', [...$photo, 'trip_month' => '2026-03'])->json('data.id');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/tour-photos/{$id}", $photo)->assertOk()->assertJsonPath('data.trip_month', '2026-03');
    }

    #[Test]
    public function a_picture_the_gallery_shows_cannot_be_deleted_and_only_website_staff_may_edit_the_list(): void
    {
        $admin = $this->staff('admin');
        $picture = $this->picture('group');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/tour-photos', ['caption_bn' => 'কাঠমান্ডু', 'caption_en' => 'Kathmandu', 'media_id' => $picture->id])->assertCreated();

        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$picture->id}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

        foreach (['tour_operator', 'sales_agent', 'accountant'] as $role) {
            $staff = $this->staff($role);
            $this->actingAsApi($staff)->getJson('/api/v1/admin/tour-photos')->assertForbidden();
            $this->actingAsApi($staff)->postJson('/api/v1/admin/tour-photos', ['caption_bn' => 'ক', 'caption_en' => 'K'])->assertForbidden();
        }
    }
}
