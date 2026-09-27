<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Media;
use App\Models\Review;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/customer-reviews.md: customers' reviews with trip photos, sent from the website and approved by staff. */
class CustomerReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function a_customer_sends_a_review_with_photos_and_it_shows_only_once_staff_approve_it(): void
    {
        Bus::fake([RevalidateWebsite::class]);

        $this->send(['photos' => [$this->photo('mustang.jpg'), $this->photo('lake.png', 'png')]])->assertAccepted()->assertJsonPath('data.status', 'received');

        $review = Review::query()->with('photos.image')->sole();
        $this->assertSame(['customer', '8801711000321', 5], [$review->source, $review->phone, $review->rating]);
        $this->assertTrue($review->isPending());
        $this->assertCount(2, $review->photos);
        // Photos go through the media library: WebP, the phone's location data dropped.
        $this->assertStringEndsWith('.webp', $review->photos->first()->image->url());
        // Nothing on the website yet.
        $this->getJson('/api/v1/public/reviews')->assertOk()->assertJsonCount(0, 'data');

        // Staff see it waiting, with its photos and number; the published list doesn't show it.
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews/pending')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.phone', '8801711000321')->assertJsonCount(2, 'data.0.photos')->assertJsonPath('data.0.pending', true);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonCount(0, 'data');

        // One photo hidden, then approved: on the website with the other photo, its package, and no phone number.
        $photos = $review->photos;
        $this->actingAsApi($admin)->putJson("/api/v1/admin/review-photos/{$photos[1]->id}", ['is_shown' => false])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertStatus(409)->assertJsonPath('code', 'review_decided');
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['reviews']);

        $public = $this->getJson('/api/v1/public/reviews')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('Tanvir Hasan', $public['reviewerName']);
        $this->assertSame(self::MUSTANG, $public['packageSlug']);
        $this->assertFalse($public['verified']);
        $this->assertCount(1, $public['photos']);
        $this->assertStringNotContainsString('8801711000321', json_encode($public));
        // It is now in the Reviews list staff edit and order.
        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['review.submitted', 'review.photo_hidden', 'review.approved'], AuditLog::query()->where('auditable_type', 'review')->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function a_rejected_review_never_shows_and_a_reviewer_who_travelled_with_us_is_marked_verified(): void
    {
        $admin = $this->staff('admin');
        // A confirmed booking with this number: the review is marked as from someone who travelled.
        $reference = $this->postJson('/api/v1/public/bookings', [
            'package_slug' => self::MUSTANG, 'travel_date' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'pax' => 2, 'room' => 'twin', 'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000321'], []], 'expected_total' => 153000, 'terms_accepted' => true, 'locale' => 'en',
        ])->assertCreated()->json('data.reference');
        Booking::query()->where('reference', $reference)->update(['status' => BookingStatus::Confirmed->value]);

        $this->send()->assertAccepted();
        $this->send(['phone' => '01811-000999', 'name' => 'Someone Else'])->assertAccepted();
        [$verified, $other] = Review::query()->with('booking')->orderBy('id')->get();
        $this->assertSame($reference, $verified->booking->reference);
        $this->assertNull($other->booking_id);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/reviews/{$other->id}/reject", ['reason' => 'Not about a trip'])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/reviews/{$verified->id}/approve")->assertOk()->assertJsonPath('data.booking.reference', $reference);

        $public = $this->getJson('/api/v1/public/reviews')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertTrue($public['verified']);
        $this->assertSame('Not about a trip', $other->fresh()->reject_reason);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews/pending')->assertJsonPath('meta.total', 0);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews')->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_form_checks_what_it_is_sent_and_keeps_spam_out(): void
    {
        $pictures = Media::query()->count();
        $this->send(['rating' => 6, 'review' => 'Too short', 'phone' => '12345', 'name' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'review', 'phone', 'name']);
        // A trip is needed: a package, or the trip in the customer's words.
        $this->send(['package_slug' => null])->assertUnprocessable()->assertJsonValidationErrors('trip');
        $this->send(['package_slug' => null, 'trip' => 'Cox\'s Bazar family trip'])->assertAccepted();
        // (The public forms' own limit is five a minute per address; the clock moves on between batches.)
        $this->travel(2)->minutes();
        // At most five photos, pictures only, 8 MB each.
        $this->send(['photos' => array_map(fn ($i) => $this->photo("p{$i}.jpg"), range(1, 6))])->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->send(['photos' => [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')]])->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $this->send(['photos' => [UploadedFile::fake()->image('big.jpg')->size(8193)]])->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        // The hidden field bots fill: the same answer, nothing stored.
        $before = Review::query()->count();
        $this->send(['company' => 'Spam Ltd'])->assertAccepted();
        $this->assertSame($before, Review::query()->count());
        $this->travel(2)->minutes();
        // Three a day per number.
        $this->send(['phone' => '01911-000111'])->assertAccepted();
        $this->send(['phone' => '01911-000111'])->assertAccepted();
        $this->send(['phone' => '01911-000111'])->assertAccepted();
        $this->send(['phone' => '01911-000111'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        // Nothing half-saved from the refused ones: no stray pictures.
        $this->assertSame($pictures, Media::query()->count());
    }

    #[Test]
    public function reviews_staff_write_are_listed_straight_away_as_before(): void
    {
        $admin = $this->staff('admin');
        $id = $this->actingAsApi($admin)->postJson('/api/v1/admin/reviews', ['quote_bn' => 'খুব ভালো ব্যবস্থাপনা।', 'reviewer_name' => 'Nusrat Jahan', 'rating' => 5])
            ->assertCreated()->assertJsonPath('data.source', 'staff')->assertJsonPath('data.pending', false)->json('data.id');

        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/reviews/pending')->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function only_website_staff_decide_on_reviews(): void
    {
        $this->send()->assertAccepted();
        $id = Review::query()->sole()->id;
        foreach (['sales_agent', 'accountant', 'tour_operator'] as $role) {
            $staff = $this->staff($role);
            $this->actingAsApi($staff)->getJson('/api/v1/admin/reviews/pending')->assertForbidden();
            $this->actingAsApi($staff)->postJson("/api/v1/admin/reviews/{$id}/approve")->assertForbidden();
        }
        $this->assertTrue(Review::query()->sole()->isPending());
    }

    /** @param array<string, mixed> $overrides */
    private function send(array $overrides = []): TestResponse
    {
        return $this->post('/api/v1/public/reviews', array_filter([
            'name' => 'Tanvir Hasan', 'phone' => '01711-000321', 'package_slug' => self::MUSTANG, 'travelled_month' => now('Asia/Dhaka')->subMonth()->format('Y-m'),
            'rating' => 5, 'review' => 'Mustang was beyond what we imagined — the guide knew every village and the hotels were spotless.', 'locale' => 'en',
            ...$overrides,
        ], fn ($value) => $value !== null), ['Accept' => 'application/json']);
    }

    private function photo(string $name, string $type = 'jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 900)->mimeType($type === 'png' ? 'image/png' : 'image/jpeg');
    }
}
