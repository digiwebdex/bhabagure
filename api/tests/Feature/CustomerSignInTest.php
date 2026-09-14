<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Models\NotificationMessage;
use App\Services\Auth\RefreshTokens;
use App\Services\Notifications\SendResult;
use App\Services\Notifications\Sms\FakeSmsGateway;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-6-customer-portal.md §0.1, §3.1: customers sign in with a one-time code — no passwords. The first code on
 * a known number claims its record; answers never reveal whether a number belongs to a customer; codes are limited,
 * short-lived, stored hashed and never written to the message log.
 */
class CustomerSignInTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function a_customer_staff_created_signs_in_with_a_code_and_the_first_sign_in_claims_the_record(): void
    {
        $customer = $this->customer(['name' => 'Tanvir Hasan', 'phone' => '8801711000123', 'password' => null, 'stage' => 'lead']);

        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711-000123'])->assertAccepted()->assertJsonPath('data.status', 'sent');
        $code = $this->lastCode('8801711000123');
        $this->assertSame(64, strlen((string) CustomerLoginCode::query()->value('code_hash')), 'only a hash is stored');

        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
        $signedIn = $this->postJson('/api/v1/customer/auth/verify', ['phone' => '০১৭১১০০০১২৩', 'code' => $code])
            ->assertOk()->assertJsonPath('customer.id', $customer->id)->assertJsonPath('customer.name', 'Tanvir Hasan')->assertJsonStructure(['access_token', 'expires_in']);
        $this->assertNotNull($signedIn->getCookie(RefreshTokens::cookieName('customer'), decrypt: false));

        $customer->refresh();
        $this->assertNotNull($customer->portal_claimed_at);
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.customer.portal_claimed')->exists());

        // The code is used up; a later sign-in is an ordinary one.
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $code])->assertUnprocessable();
        $this->travel(2)->minutes();
        $this->resetAuthState();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '+8801711000123'])->assertAccepted();
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $this->lastCode('8801711000123')])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'auth.customer.login')->exists());

        // A code is a credential: it never reaches the message log staff read.
        $this->assertSame(0, NotificationMessage::query()->count());
        $this->assertStringNotContainsString($code, (string) AuditLog::query()->pluck('changes')->toJson());
    }

    #[Test]
    public function a_number_with_no_record_gets_the_same_answer_and_becomes_a_lead_once_it_gives_a_name(): void
    {
        $this->customer(['phone' => '8801711000123']);
        $known = $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted()->json();
        $unknown = $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000456'])->assertAccepted()->json();
        $this->assertSame($known, $unknown, 'the answer never says whether a number is a customer');

        $code = $this->lastCode('8801711000456');
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000456', 'code' => $code])->assertUnprocessable()->assertJsonPath('code', 'name_required');
        // The code still works: the number is proven, only the name was missing.
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000456', 'code' => $code, 'name' => 'Nusrat Jahan', 'locale' => 'en'])->assertOk()
            ->assertJsonPath('customer.name', 'Nusrat Jahan');
        $lead = Customer::query()->where('phone', '8801711000456')->firstOrFail();
        $this->assertSame(['lead', 'website_form', 'en'], [$lead->stage, $lead->source, $lead->locale]);
        $this->assertNull($lead->password);
    }

    #[Test]
    public function codes_are_limited_short_lived_single_use_and_only_the_newest_works(): void
    {
        $this->customer(['phone' => '8801711000123']);

        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        $first = $this->lastCode('8801711000123');
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertStatus(429)->assertJsonPath('code', 'throttled');

        // A new code a minute later: the first stops working.
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        $second = $this->lastCode('8801711000123');
        if ($first !== $second) {
            $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $first])->assertUnprocessable();
        }

        // Five wrong tries and the code is dead, even when the sixth is right.
        foreach (range(1, 5) as $try) {
            $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $second === '999999' ? '999998' : '999999'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $second])->assertUnprocessable();

        // Ten minutes and a code expires.
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        $third = $this->lastCode('8801711000123');
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $third])->assertUnprocessable();

        // Five codes an hour for one number.
        foreach (range(1, 2) as $more) {
            $this->travel(61)->seconds();
            $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertStatus(429);
    }

    #[Test]
    public function when_sms_cannot_deliver_the_code_goes_by_whatsapp_unless_the_customer_turned_whatsapp_off(): void
    {
        FakeSmsGateway::$next = SendResult::skipped('sms_off');
        $this->customer(['phone' => '8801711000123']);

        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        $this->assertCount(1, FakeWhatsAppGateway::$sent);
        $this->assertSame('whatsapp', CustomerLoginCode::query()->value('channel'));

        $this->customer(['phone' => '8801711000789', 'email' => 'optout@example.test'])->forceFill(['whatsapp_opted_out_at' => now()])->save();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000789'])->assertStatus(503)->assertJsonPath('code', 'code_undeliverable');
        $this->assertCount(1, FakeWhatsAppGateway::$sent, 'no WhatsApp to a customer who turned it off');
    }

    #[Test]
    public function staff_can_stop_a_customer_signing_in_and_an_open_session_ends_at_its_next_refresh(): void
    {
        $customer = $this->customer(['phone' => '8801711000123']);
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        $cookie = $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $this->lastCode('8801711000123')])->assertOk()
            ->getCookie(RefreshTokens::cookieName('customer'), decrypt: false);

        $customer->forceFill(['portal_disabled_at' => now()])->save();
        $this->resetAuthState();
        $this->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName('customer'), $cookie->getValue())->postJson('/api/v1/customer/auth/refresh')
            ->assertUnauthorized();

        $this->travel(2)->minutes();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000123'])->assertAccepted();
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000123', 'code' => $this->lastCode('8801711000123')])
            ->assertForbidden()->assertJsonPath('code', 'portal_disabled');
    }

    #[Test]
    public function the_password_endpoints_are_gone(): void
    {
        $this->postJson('/api/v1/customer/auth/login', ['identifier' => '01711000123', 'password' => 'secret123'])->assertNotFound();
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'X', 'phone' => '01711000123', 'password' => 'secret123'])->assertNotFound();
    }

    /** The six digits in the last SMS or WhatsApp to this number. */
    private function lastCode(string $phone): string
    {
        $messages = array_filter([...FakeSmsGateway::$sent, ...FakeWhatsAppGateway::$sent], fn (array $m) => str_contains($m['to'], substr($phone, -10)));
        $this->assertNotEmpty($messages, "no code sent to {$phone}");
        preg_match('/\d{6}/', (string) end($messages)['text'], $match);

        return $match[0];
    }
}
