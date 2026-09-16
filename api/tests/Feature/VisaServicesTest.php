<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\AuditLog;
use App\Models\VisaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/phase-8-visa-quotes-pricing-downloads.md §4.C: visa services on Admin → Visa services and the website. */
class VisaServicesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_draft_needs_processing_time_and_requirements_in_both_languages_before_it_is_published(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');

        $created = $this->actingAsApi($admin)->postJson('/api/v1/admin/visas', [
            'country_code' => 'th', 'country_bn' => 'থাইল্যান্ড', 'country_en' => 'Thailand', 'visa_type_bn' => 'টুরিস্ট ভিসা', 'visa_type_en' => 'Tourist visa',
            'price' => 5500, 'requirements_en' => "Passport valid for 6 months\n\n  Two photos (35 × 45 mm)  \nBank statement, last 6 months", 'requirements_bn' => '',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'thailand-tourist-visa')->assertJsonPath('data.country_code', 'TH')
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.price', 5500)->assertJsonPath('data.requirements_bn', null)
            ->json('data');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/visas/{$created['id']}/publish")->assertUnprocessable()
            // Staff screens are English only (docs/phase-5-admin-core.md, 2026-09-16).
            ->assertJsonPath('problems', ['Say how long processing takes.', 'List the requirements in both languages, one per line.']);
        $this->getJson('/api/v1/public/visas')->assertOk()->assertExactJson(['data' => []]);

        $this->actingAsApi($admin)->putJson("/api/v1/admin/visas/{$created['id']}", [
            ...$created, 'processing_bn' => '৭–১০ কর্মদিবস', 'processing_en' => '7–10 working days', 'stay_en' => 'Single entry, up to 60 days',
            'requirements_bn' => "৬ মাস মেয়াদি পাসপোর্ট\nদুই কপি ছবি\nশেষ ৬ মাসের ব্যাংক স্টেটমেন্ট",
        ])->assertOk();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/visas/{$created['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['visas']);

        $this->getJson('/api/v1/public/visas')->assertOk()->assertExactJson(['data' => [[
            'slug' => 'thailand-tourist-visa',
            'countryCode' => 'TH',
            'country' => ['bn' => 'থাইল্যান্ড', 'en' => 'Thailand'],
            'visaType' => ['bn' => 'টুরিস্ট ভিসা', 'en' => 'Tourist visa'],
            'price' => 5500,
            'processing' => ['bn' => '৭–১০ কর্মদিবস', 'en' => '7–10 working days'],
            // Bangla stay not written yet: the English shows in both languages rather than a blank.
            'stay' => ['bn' => 'Single entry, up to 60 days', 'en' => 'Single entry, up to 60 days'],
            'requirements' => [
                'bn' => ['৬ মাস মেয়াদি পাসপোর্ট', 'দুই কপি ছবি', 'শেষ ৬ মাসের ব্যাংক স্টেটমেন্ট'],
                'en' => ['Passport valid for 6 months', 'Two photos (35 × 45 mm)', 'Bank statement, last 6 months'],
            ],
            'notes' => null,
            'updatedAt' => VisaService::query()->sole()->updated_at->toIso8601String(),
        ]]]);
        $this->assertSame(['cms.visa_service.created', 'cms.visa_service.updated', 'cms.visa_service.published'], AuditLog::query()->where('auditable_type', 'visa_service')->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function slugs_are_unique_addresses_codes_are_two_letters_and_price_may_be_left_for_on_request(): void
    {
        $admin = $this->staff('admin');
        $base = ['country_bn' => 'মালয়েশিয়া', 'country_en' => 'Malaysia', 'visa_type_bn' => 'ই-ভিসা', 'visa_type_en' => 'eVisa'];

        $this->actingAsApi($admin)->postJson('/api/v1/admin/visas', $base)->assertCreated()->assertJsonPath('data.slug', 'malaysia-evisa')->assertJsonPath('data.price', null);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/visas', $base)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/visas', [...$base, 'slug' => 'Malaysia Business!', 'country_code' => 'MYS', 'price' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['country_code', 'price'])->assertJsonMissingValidationErrors('slug');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/visas', ['country_en' => 'Malaysia'])->assertUnprocessable()
            ->assertJsonValidationErrors(['country_bn', 'visa_type_bn', 'visa_type_en']);

        foreach (['tour_operator', 'sales_agent', 'accountant'] as $role) {
            $this->actingAsApi($this->staff($role))->getJson('/api/v1/admin/visas')->assertForbidden();
        }
    }
}
