<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\Booking;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/legal-pages.md: the booking terms, refund policy and privacy policy, edited in the admin and kept in versions. */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function before_anything_is_published_the_website_keeps_its_draft_and_the_editor_starts_from_it(): void
    {
        $this->getJson('/api/v1/public/legal')->assertOk()->assertExactJson(['data' => ['terms' => null, 'refund-policy' => null, 'privacy' => null]]);

        $page = $this->actingAsApi($this->staff('admin'))->getJson('/api/v1/admin/legal-pages/terms')->assertOk()
            ->assertJsonPath('data.published', false)->assertJsonPath('data.versions', [])->json('data.text');
        $this->assertSame('বুকিংয়ের শর্তাবলী', $page['title_bn']);
        $this->assertStringContainsString('<h2>', $page['body_en']);
        $this->actingAsApi($this->staff('admin'))->getJson('/api/v1/admin/legal-pages')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.current', null);
    }

    #[Test]
    public function each_publish_is_a_new_version_the_website_shows_the_latest_and_old_ones_stay_readable(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');
        $publish = fn (array $data) => $this->actingAsApi($admin)->postJson('/api/v1/admin/legal-pages/terms', $data + ['title_bn' => 'বুকিংয়ের শর্তাবলী', 'title_en' => 'Booking terms']);

        $publish(['body_bn' => ''])->assertUnprocessable()->assertJsonValidationErrors('body_bn');
        $publish(['body_bn' => '<p>প্রথম সংস্করণ</p><script>alert(1)</script>', 'body_en' => '<h2>1. Cancellation</h2><p>First version</p>', 'note' => 'Our own terms'])
            ->assertCreated()->assertJsonPath('data.version', 1);
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['legal']);
        $publish(['body_bn' => '<p>দ্বিতীয় সংস্করণ</p>'])->assertCreated()->assertJsonPath('data.version', 2);

        // The website: the latest, sanitised; English left empty shows the Bangla.
        $this->getJson('/api/v1/public/legal')->assertOk()
            ->assertJsonPath('data.terms.version', 2)->assertJsonPath('data.terms.body.bn', '<p>দ্বিতীয় সংস্করণ</p>')
            ->assertJsonPath('data.terms.body.en', '<p>দ্বিতীয় সংস্করণ</p>')->assertJsonPath('data.privacy', null);

        // The admin: both versions, newest first, and the first one still as it was (without the script).
        $this->actingAsApi($admin)->getJson('/api/v1/admin/legal-pages/terms')->assertOk()
            ->assertJsonPath('data.published', true)->assertJsonPath('data.text.body_bn', '<p>দ্বিতীয় সংস্করণ</p>')
            ->assertJsonPath('data.versions.0.version', 2)->assertJsonPath('data.versions.1.note', 'Our own terms')
            ->assertJsonPath('data.versions.1.published_by', $admin->name);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/legal-pages/terms/versions/1')->assertOk()
            ->assertJsonPath('data.body_bn', '<p>প্রথম সংস্করণ</p>')->assertJsonPath('data.body_en', '<h2>1. Cancellation</h2><p>First version</p>');
        $this->assertSame(2, AuditLog::query()->where('action', 'legal_page.published')->count());

        // Unknown pages and staff without the website permission are refused.
        $this->actingAsApi($admin)->postJson('/api/v1/admin/legal-pages/cookies', ['title_bn' => 'x', 'body_bn' => '<p>x</p>'])->assertNotFound();
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/legal-pages')->assertForbidden();
    }

    #[Test]
    public function a_booking_records_the_terms_version_the_customer_accepted(): void
    {
        $book = fn () => Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', [
            'package_slug' => 'nepal-mustang-adventure-tour-8-days-7-nights', 'travel_date' => now('Asia/Dhaka')->addDays(40)->toDateString(),
            'pax' => 2, 'room' => 'twin', 'addons' => [], 'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000321'], []],
            'expected_total' => 153000, 'terms_accepted' => true, 'locale' => 'en',
        ])->assertCreated()->json('data.reference'))->sole();

        // Before publishing: the built-in draft.
        $this->assertSame('draft-2026-09', $book()->terms_version);

        $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/legal-pages/terms', ['title_bn' => 'শর্তাবলী', 'body_bn' => '<p>নতুন</p>'])->assertCreated();
        $this->assertSame('terms-v1', $book()->terms_version);
    }
}
