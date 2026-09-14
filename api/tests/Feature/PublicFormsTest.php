<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\NewsletterSubscriber;
use App\Models\TourPackage;
use App\Support\NewsletterUnsubscribeToken;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The payloads here are exactly what the website sends (web/src/features/contact, search, newsletter). */
class PublicFormsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_contact_form_creates_an_inquiry_linked_to_the_package(): void
    {
        $this->seed(ContentSeeder::class);
        $package = TourPackage::query()->published()->firstOrFail();

        $this->postJson('/api/v1/public/inquiries', [
            'name' => 'Tanvir Hasan', 'phone' => '8801711000123', 'package_slug' => $package->slug, 'travellers' => 4, 'locale' => 'en',
        ])->assertAccepted();

        $inquiry = Inquiry::query()->sole();
        $this->assertSame([$package->id, 4, 'contact', 'en'], [$inquiry->tour_package_id, $inquiry->pax, $inquiry->type->value, $inquiry->locale]);
    }

    #[Test]
    public function the_air_ticket_form_stores_route_dates_and_cabin(): void
    {
        $this->postJson('/api/v1/public/air-quotes', [
            'from_place' => 'Dhaka', 'to_place' => 'Kathmandu', 'depart_on' => now()->addWeek()->toDateString(), 'return_on' => null,
            'passengers' => '2', 'cabin_class' => 'premium', 'name' => 'Sadia', 'phone' => '8801711000123', 'email' => null, 'locale' => 'bn',
        ])->assertAccepted();

        $this->assertSame('Kathmandu', Inquiry::query()->sole()->details['to']);
    }

    #[Test]
    public function invalid_submissions_get_field_errors(): void
    {
        $this->postJson('/api/v1/public/inquiries', ['name' => '', 'phone' => '12345'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);

        $this->postJson('/api/v1/public/air-quotes', ['depart_on' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['depart_on', 'from_place', 'cabin_class']);
    }

    #[Test]
    public function a_filled_honeypot_looks_accepted_but_stores_nothing(): void
    {
        $this->postJson('/api/v1/public/inquiries', ['name' => 'Bot', 'phone' => '8801711000123', 'company' => 'Spam Ltd'])->assertAccepted();
        $this->postJson('/api/v1/public/newsletter', ['email' => 'bot@example.test', 'company' => 'Spam Ltd'])->assertAccepted();

        $this->assertSame(0, Inquiry::query()->count());
        $this->assertSame(0, NewsletterSubscriber::query()->count());
    }

    #[Test]
    public function forms_are_rate_limited_per_visitor(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/public/newsletter', ['email' => "person{$i}@example.test"])->assertAccepted();
        }

        $this->postJson('/api/v1/public/newsletter', ['email' => 'one-more@example.test'])->assertTooManyRequests();
    }

    #[Test]
    public function newsletter_signup_is_idempotent_and_the_signed_link_unsubscribes_with_a_post_and_no_login(): void
    {
        $this->postJson('/api/v1/public/newsletter', ['email' => 'Reader@Example.test', 'locale' => 'bn'])->assertAccepted();
        $this->postJson('/api/v1/public/newsletter', ['email' => 'reader@example.test'])->assertAccepted();

        $subscriber = NewsletterSubscriber::query()->sole();
        $token = NewsletterUnsubscribeToken::for($subscriber);
        $this->assertStringEndsWith("/newsletter/unsubscribe/{$token}", NewsletterUnsubscribeToken::url($subscriber));

        $this->getJson("/api/v1/public/newsletter/unsubscribe/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'subscribed')->assertJsonPath('data.email', 're****@example.test');
        $this->assertSame('subscribed', $subscriber->fresh()->status);

        $this->postJson("/api/v1/public/newsletter/unsubscribe/{$token}")->assertOk();
        $this->assertSame('unsubscribed', $subscriber->fresh()->status);
    }

    #[Test]
    public function unsubscribe_links_cannot_be_forged_guessed_or_reused_after_rotation(): void
    {
        $this->postJson('/api/v1/public/newsletter', ['email' => 'a@example.test'])->assertAccepted();
        $this->postJson('/api/v1/public/newsletter', ['email' => 'b@example.test'])->assertAccepted();
        [$first, $second] = NewsletterSubscriber::query()->orderBy('id')->get()->all();
        $token = NewsletterUnsubscribeToken::for($first);
        $signature = substr($token, strpos($token, '-') + 1);

        foreach ([
            'raw stored token' => $first->unsubscribe_token,
            'other subscriber id' => "{$second->id}-{$signature}",
            'tampered signature' => $first->id.'-'.strrev($signature),
            'garbage' => 'not-a-real-token',
        ] as $case => $forged) {
            $this->postJson("/api/v1/public/newsletter/unsubscribe/{$forged}")->assertForbidden()->assertJsonPath('code', 'invalid_link');
        }
        $this->assertSame(0, NewsletterSubscriber::query()->where('status', 'unsubscribed')->count());

        $first->forceFill(['unsubscribe_token' => Str::random(40)])->save();
        $this->postJson("/api/v1/public/newsletter/unsubscribe/{$token}")->assertForbidden();
    }
}
