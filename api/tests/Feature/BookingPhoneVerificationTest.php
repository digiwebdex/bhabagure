<?php

namespace Tests\Feature;

use App\Mail\AdminAlertMail;
use App\Mail\LoginCodeMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Models\NotificationMessage;
use App\Models\SiteSetting;
use App\Services\Booking\PhoneCheck;
use App\Services\Customers\LoginCodes;
use App\Services\Notifications\SendResult;
use App\Services\Notifications\Sms\FakeSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/booking-phone-verification.md: while Site settings asks for it, a website booking is saved only with the code sent
 * to the lead traveller's mobile — and the right code saves one booking. Off (the default, and on live until SMS reaches
 * customers), a website booking is exactly as before. Mustang for two is ৳1,53,000.
 */
class BookingPhoneVerificationTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        // Fake SMS and WhatsApp, and the content: codes are read from what the SMS stand-in "sent".
        $this->sendNotifications();
    }

    #[Test]
    public function switched_off_a_website_booking_is_exactly_as_before(): void
    {
        $this->getJson('/api/v1/public/pricing')->assertOk()->assertJsonPath('data.verifyPhone', false);
        // No code to send while nothing asks for one — and no SMS to pay for.
        $this->sendCode()->assertNotFound();
        $this->assertSame([], FakeSmsGateway::$sent);

        $reference = $this->postJson('/api/v1/public/bookings', $this->payload())->assertCreated()->json('data.reference');
        $this->assertNull(Booking::query()->where('reference', $reference)->sole()->phone_verified_at);
    }

    #[Test]
    public function switched_on_nothing_is_saved_without_the_right_code(): void
    {
        $this->checkOn();
        $this->getJson('/api/v1/public/pricing')->assertJsonPath('data.verifyPhone', true);

        $this->postJson('/api/v1/public/bookings', $this->payload())->assertUnprocessable()
            ->assertJsonPath('code', 'verification_required')->assertJsonPath('message', 'Enter the code we sent to your mobile and email to confirm the booking.');

        $this->sendCode()->assertAccepted()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.expires_in', 600)->assertJsonPath('data.retry_after', 60);
        $sms = end(FakeSmsGateway::$sent);
        $this->assertSame('8801711000321', $sms['to']);
        $this->assertMatchesRegularExpression('/^Bhabaghure Holidays booking code: \d{6}\. Valid for 10 minutes\. Never share it with anyone\.$/', $sms['text']);
        $code = $this->lastCode();

        // Stored only as an HMAC, for this purpose, and never in the message log staff read.
        $row = CustomerLoginCode::query()->sole();
        // Every channel at once (the tests' mailer doesn't really send, so no email here).
        $this->assertSame(['booking', 'sms,whatsapp'], [$row->purpose, $row->channel]);
        $this->assertStringNotContainsString($code, $row->code_hash);
        $this->assertSame(0, NotificationMessage::query()->where('body', 'like', "%{$code}%")->count());

        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code === '000000' ? '111111' : '000000']))
            ->assertUnprocessable()->assertJsonPath('code', 'verification_invalid')->assertJsonPath('message', 'That code is wrong or has expired. Ask for a new one.');
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, Customer::query()->count(), 'no lead is made from a booking that was never saved');

        // In Bangla, the code arrives in Bangla.
        $this->travel(2)->minutes();
        $this->sendCode(locale: 'bn')->assertAccepted();
        $this->assertStringStartsWith('ভবঘুরে হলিডেজ বুকিং কোড: ', end(FakeSmsGateway::$sent)['text']);
    }

    #[Test]
    public function the_right_code_saves_one_booking_and_proves_the_number(): void
    {
        $this->checkOn();
        $this->sendCode()->assertAccepted();
        $code = $this->lastCode();

        // A changed price is refused without using the code up: the customer tries again with the same code.
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code, 'expected_total' => 1]))->assertStatus(409)->assertJsonPath('code', 'price_changed');
        $this->assertNull(CustomerLoginCode::query()->sole()->consumed_at);

        $key = (string) Str::uuid();
        $reference = $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code, 'idempotency_key' => $key]))->assertCreated()->json('data.reference');
        $booking = Booking::query()->where('reference', $reference)->sole();
        $this->assertNotNull($booking->phone_verified_at);
        $this->assertNotNull(CustomerLoginCode::query()->sole()->consumed_at);
        $this->assertNotNull(Customer::query()->where('phone', '8801711000321')->sole()->phone_verified_at, 'the code proved the customer\'s own number');

        // The same attempt sent again answers as before; the same code can't save a second booking.
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code, 'idempotency_key' => $key]))->assertStatus(409)->assertJsonPath('code', 'already_created');
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code]))->assertUnprocessable()->assertJsonPath('code', 'verification_invalid');
        $this->assertSame(1, Booking::query()->count());

        // Staff see that the number was proven.
        $this->actingAsApi($this->staff('admin'))->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.phone_verified_at', $booking->phone_verified_at->toIso8601String());
    }

    #[Test]
    public function a_code_proves_only_its_own_number_its_own_purpose_and_only_for_ten_minutes_and_five_tries(): void
    {
        $this->checkOn();

        // A portal sign-in code for the same number doesn't confirm a booking.
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000321'])->assertAccepted();
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $this->lastCode()]))->assertUnprocessable()->assertJsonPath('code', 'verification_invalid');

        // Nor does a booking code sent to another number.
        $this->sendCode('01811000999')->assertAccepted();
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $this->lastCode()]))->assertUnprocessable()->assertJsonPath('code', 'verification_invalid');

        // Its own code, eleven minutes later: expired.
        $this->travel(2)->minutes();
        $this->sendCode()->assertAccepted();
        $code = $this->lastCode();
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code]))->assertUnprocessable()->assertJsonPath('code', 'verification_invalid');

        // Five wrong tries use a code up — the right one is refused after them.
        $this->sendCode()->assertAccepted();
        $code = $this->lastCode();
        foreach (range(1, 5) as $try) {
            $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code === '123456' ? '654321' : '123456']))->assertUnprocessable();
        }
        $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code]))->assertUnprocessable()->assertJsonPath('code', 'verification_invalid');
        $this->assertSame(0, Booking::query()->count());
    }

    #[Test]
    public function codes_are_limited_and_one_that_cant_be_sent_says_so_and_alerts_staff(): void
    {
        $this->checkOn();
        $this->sendCode()->assertAccepted();
        // One a minute per number (and five an hour), as for sign-in codes.
        $this->sendCode()->assertStatus(429)->assertJsonPath('code', 'throttled')->assertJsonPath('retry_after', fn (int $seconds) => $seconds > 0 && $seconds <= 60);
        $this->sendCode('12345')->assertUnprocessable()->assertJsonValidationErrors('phone');

        // Neither SMS nor WhatsApp can send it — as on live until bulksmsbd lets the server in.
        $this->sendNotifications(null);
        FakeSmsGateway::$next = SendResult::skipped('sms_off');
        $this->staff('admin');
        $this->sendCode('01811000777')->assertStatus(503)->assertJsonPath('code', 'code_undeliverable')
            ->assertJsonPath('message', 'We can’t send the booking code right now. Please call or WhatsApp our office to book.');
        $this->assertSame('none', CustomerLoginCode::query()->latest('id')->value('channel'));
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => str_contains($mail->alert, 'tried to book on the website'));
    }

    #[Test]
    public function while_the_check_is_on_the_lead_gives_an_email_and_the_code_goes_there_too(): void
    {
        $this->checkOn();
        $this->emailWorks();

        // No email: refused, saying why, before any code is sent.
        $noEmail = $this->payload();
        unset($noEmail['travellers'][0]['email']);
        $this->postJson('/api/v1/public/bookings', $noEmail)->assertUnprocessable()
            ->assertJsonValidationErrors(['travellers.0.email' => 'Add the lead traveller’s email: the booking code goes there too.']);
        $this->sendCode(email: null)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame([], FakeSmsGateway::$sent);

        // Every channel at once, and the form is told which took it.
        $this->sendCode()->assertAccepted()->assertJsonPath('data.channels', fn (array $channels) => in_array('sms', $channels, true) && in_array('email', $channels, true));
        Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('tanvir@example.test') && $mail->code === $this->lastCode() && $mail->purpose === 'booking');
        $this->assertStringContainsString('email', CustomerLoginCode::query()->sole()->channel);
    }

    #[Test]
    public function a_code_that_only_email_could_send_still_saves_the_booking_but_proves_no_number(): void
    {
        $this->checkOn();
        $this->emailWorks();
        // SMS and WhatsApp both down, as on live today.
        $this->sendNotifications(null);
        FakeSmsGateway::$next = SendResult::skipped('sms_off');

        $this->sendCode()->assertAccepted()->assertJsonPath('data.channels', ['email']);
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        // The right code from the email: saved, marked verified by code, and on to payment as before.
        $reference = $this->postJson('/api/v1/public/bookings', $this->payload(['verification_code' => $code]))->assertCreated()
            ->assertJsonPath('data.payment.checkout', fn ($checkout) => $checkout !== null)->json('data.reference');
        $this->assertNotNull(Booking::query()->where('reference', $reference)->sole()->phone_verified_at);
        // But the code never went to the phone, so the number itself isn't proven.
        $this->assertNull(Customer::query()->where('phone', '8801711000321')->sole()->phone_verified_at);
    }

    #[Test]
    public function portal_sign_in_codes_go_by_email_too_and_phone_change_codes_never_do(): void
    {
        $this->emailWorks();
        Customer::query()->create(['name' => 'Tanvir Hasan', 'phone' => '8801711000321', 'email' => 'tanvir@example.test', 'stage' => 'lead', 'source' => 'walk_in', 'locale' => 'en']);

        // Sign-in: a copy to the account's address; the answer doesn't say where the code went, so it reveals no account.
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000321', 'locale' => 'en'])->assertAccepted()->assertJsonMissingPath('data.channels');
        Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('tanvir@example.test') && $mail->purpose === 'sign_in');

        // A phone-change code must prove the new number itself: never by email, even with an address given.
        Mail::fake();
        $this->travel(2)->minutes();
        app(LoginCodes::class)->send('8801811000444', 'en', null, LoginCodes::CHANGE_PHONE, 'tanvir@example.test');
        Mail::assertNotSent(LoginCodeMail::class);
    }

    #[Test]
    public function office_bookings_never_need_a_code(): void
    {
        $this->checkOn();

        $this->actingAsApi($this->staff('sales_agent'))->postJson('/api/v1/admin/bookings', [
            'customer' => ['name' => 'Karim Uddin', 'phone' => '01711-000555', 'email' => 'karim@example.test', 'source' => 'walk_in'],
            'package_slug' => self::MUSTANG, 'travel_date' => now('Asia/Dhaka')->addDays(40)->toDateString(), 'pax' => 2, 'room' => 'twin', 'addons' => [],
            'travellers' => [['name' => 'Karim Uddin'], ['name' => 'Salma Begum']], 'expected_total' => 153000, 'locale' => 'bn',
        ])->assertCreated()->assertJsonPath('data.phone_verified_at', null);
        $this->assertSame([], FakeSmsGateway::$sent);
    }

    #[Test]
    public function the_switch_lives_in_site_settings_beside_whether_codes_reach_customers(): void
    {
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('meta.codes', ['lastSentAt' => null, 'lastFailedAt' => null]);

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/booking', ['value' => []])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/booking', ['value' => ['verifyPhone' => 'yes']])->assertUnprocessable()->assertJsonValidationErrors('value.verifyPhone');
        // Only the known field is kept, as a true boolean.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/booking', ['value' => ['verifyPhone' => '1', 'extra' => 'x']])->assertOk()
            ->assertJsonPath('data.booking', ['verifyPhone' => true]);
        $this->assertTrue(PhoneCheck::required());
        $this->getJson('/api/v1/public/pricing')->assertJsonPath('data.verifyPhone', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.setting.updated']);

        // A code that reached a customer, then one that couldn't: both show beside the switch.
        $this->sendCode()->assertAccepted();
        $this->sendNotifications(null);
        FakeSmsGateway::$next = SendResult::skipped('sms_off');
        $this->sendCode('01811000777')->assertStatus(503);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/settings')
            ->assertJsonPath('meta.codes.lastSentAt', fn (?string $at) => $at !== null)
            ->assertJsonPath('meta.codes.lastFailedAt', fn (?string $at) => $at !== null)
            ->assertJsonPath('data.booking.verifyPhone', true);

        // Only those who manage the website change it.
        $this->actingAsApi($this->staff('sales_agent'))->putJson('/api/v1/admin/settings/booking', ['value' => ['verifyPhone' => false]])->assertForbidden();
        $this->assertTrue(PhoneCheck::required());
    }

    private function checkOn(): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'booking'], ['value' => ['verifyPhone' => true]]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(46)->toDateString(),
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000321', 'email' => 'tanvir@example.test'], []],
            'expected_total' => 153000,
            'terms_accepted' => true,
            'locale' => 'en',
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    private function sendCode(string $phone = '01711000321', string $locale = 'en', ?string $email = 'tanvir@example.test'): TestResponse
    {
        return $this->postJson('/api/v1/public/booking-codes', ['phone' => $phone, 'locale' => $locale, 'email' => $email]);
    }

    /** A mailer that really sends (the tests' own is "array", which LoginCodes rightly treats as not sending). */
    private function emailWorks(): void
    {
        config(['mail.default' => 'smtp']);
    }

    /** The newest six digits the SMS stand-in "sent". */
    private function lastCode(): string
    {
        $this->assertNotEmpty(FakeSmsGateway::$sent, 'no code was sent');
        preg_match('/\d{6}/', (string) end(FakeSmsGateway::$sent)['text'], $match);

        return $match[0];
    }
}
