<?php

namespace Tests\Feature;

use App\Mail\CustomerEmailCodeMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\NotificationMessage;
use App\Models\RefreshToken;
use App\Models\SiteSetting;
use App\Services\Auth\RefreshTokens;
use App\Services\Notifications\Sms\FakeSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-6-customer-portal.md §3.1, §3.6, §3.7, §0.4: the customer's own details, a new email or phone confirmed with a
 * code sent to it, the one NPS question after a trip, and the admin's portal controls.
 */
class PortalProfileTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function the_customer_edits_their_details_and_turns_whatsapp_messages_off(): void
    {
        $me = $this->customer(['name' => 'Tanvir', 'phone' => '8801711000001', 'email' => 'tanvir@example.test']);

        $this->actingAsApi($me)->getJson('/api/v1/portal/profile')->assertOk()
            ->assertExactJson(['data' => ['name' => 'Tanvir', 'phone' => '8801711000001', 'email' => 'tanvir@example.test', 'address' => null, 'locale' => 'bn', 'whatsappOptedOut' => false]]);
        $this->actingAsApi($me)->putJson('/api/v1/portal/profile', ['name' => 'Md Tanvir Hasan', 'address' => 'House 14, Road 7, Dhanmondi', 'locale' => 'en', 'whatsapp_opted_out' => true])
            ->assertOk()->assertJsonPath('data.name', 'Md Tanvir Hasan')->assertJsonPath('data.whatsappOptedOut', true)->assertJsonPath('data.locale', 'en');
        $this->assertNotNull($me->fresh()->whatsapp_opted_out_at);
        $this->assertSame(['address', 'locale', 'name', 'whatsapp_opted_out_at'], collect(AuditLog::query()->where('action', 'customer.profile_updated')->sole()->changes['fields'])->sort()->values()->all());
        // Phone and email aren't edited here.
        $this->actingAsApi($me)->putJson('/api/v1/portal/profile', ['name' => 'X', 'locale' => 'xx', 'whatsapp_opted_out' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'locale', 'whatsapp_opted_out']);
    }

    #[Test]
    public function a_new_email_or_phone_is_confirmed_with_a_code_sent_to_it_and_never_takes_another_customers(): void
    {
        $me = $this->customer(['phone' => '8801711000001', 'email' => 'tanvir@example.test']);
        $this->customer(['name' => 'Other', 'phone' => '8801711000002', 'email' => 'taken@example.test']);

        // Email: a code to the new address; the wrong code, then the right one.
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/email', ['email' => 'TANVIR@example.test'])->assertUnprocessable()->assertJsonPath('code', 'same_email');
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/email', ['email' => 'new@example.test'])->assertAccepted();
        $code = null;
        Mail::assertSent(CustomerEmailCodeMail::class, function (CustomerEmailCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('new@example.test');
        });
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/email/confirm', ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/email/confirm', ['code' => $code])->assertOk()->assertJsonPath('data.email', 'new@example.test');
        $this->assertNotNull($me->fresh()->email_verified_at);

        // Someone else's address: refused only after the code proves the address.
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/email', ['email' => 'taken@example.test'])->assertAccepted();
        $taken = null;
        Mail::assertSent(CustomerEmailCodeMail::class, function (CustomerEmailCodeMail $mail) use (&$taken) {
            $taken = $mail->hasTo('taken@example.test') ? $mail->code : $taken;

            return true;
        });
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/email/confirm', ['code' => $taken])->assertStatus(409)->assertJsonPath('code', 'email_unavailable');

        // Phone: a sign-in code for the new number doesn't confirm a change; a change code does.
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711-000003'])->assertAccepted();
        $signInCode = $this->lastSms('8801711000003');
        $this->travel(61)->seconds();
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/phone', ['phone' => '01711000003', 'code' => $signInCode])->assertUnprocessable()->assertJsonPath('code', 'invalid_code');
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/phone/code', ['phone' => '01711-000003'])->assertAccepted();
        $this->assertStringContainsString('এই নম্বর যোগ করার কোড', end(FakeSmsGateway::$sent)['text']);
        $this->actingAsApi($me)->postJson('/api/v1/portal/profile/phone', ['phone' => '01711000003', 'code' => $this->lastSms('8801711000003')])->assertOk()->assertJsonPath('data.phone', '8801711000003');
        $this->assertSame(['customer.email_changed', 'customer.phone_changed'], AuditLog::query()->whereIn('action', ['customer.email_changed', 'customer.phone_changed'])->orderBy('id')->pluck('action')->all());

        $this->travel(61)->seconds();
        $this->actingAsApi($me->fresh())->postJson('/api/v1/portal/profile/phone/code', ['phone' => '01711000002'])->assertAccepted();
        $this->actingAsApi($me->fresh())->postJson('/api/v1/portal/profile/phone', ['phone' => '01711000002', 'code' => $this->lastSms('8801711000002')])
            ->assertStatus(409)->assertJsonPath('code', 'phone_unavailable');
        $this->assertSame('8801711000003', $me->fresh()->phone);
    }

    #[Test]
    public function after_a_completed_trip_nps_is_asked_once_promoters_get_the_review_link_and_detractors_a_follow_up(): void
    {
        $contact = SiteSetting::get('contact', []);
        SiteSetting::query()->updateOrCreate(['key' => 'contact'], ['value' => ['facebook' => 'https://facebook.com/bhabaghure'] + $contact]);
        $owner = $this->staff('sales_agent', ['email' => 'owner@example.test']);
        [$first, $second] = [$this->completedTrip(40), $this->completedTrip(10)];
        DB::table('bookings')->where('id', $second->id)->update(['assigned_staff_id' => $owner->id]);
        $me = $first->customer;

        $this->actingAsApi($me)->getJson('/api/v1/portal/nps')->assertOk()->assertJsonPath('data.prompt.reference', $second->reference);
        $this->actingAsApi($me)->postJson("/api/v1/portal/trips/{$second->reference}/nps", ['score' => 11])->assertUnprocessable();
        $this->actingAsApi($me)->postJson("/api/v1/portal/trips/{$second->reference}/nps", ['score' => 4, 'comment' => 'The hotel in Pokhara was noisy.'])->assertCreated()
            ->assertExactJson(['data' => ['score' => 4, 'reviewUrl' => null, 'followUp' => true]]);
        $this->actingAsApi($me)->postJson("/api/v1/portal/trips/{$second->reference}/nps", ['score' => 9])->assertStatus(409)->assertJsonPath('code', 'already_answered');

        $followUp = CustomerContact::query()->where('customer_id', $me->id)->sole();
        $this->assertSame(['portal', 'nps_detractor', null], [$followUp->channel, $followUp->outcome, $followUp->staff_id]);
        $this->assertStringContainsString('The hotel in Pokhara was noisy.', $followUp->note);
        $alert = NotificationMessage::query()->where('event', 'nps_follow_up_alert')->where('channel', 'email')->sole();
        $this->assertSame('owner@example.test', $alert->to_address);

        // The older trip is next; a 10 gets the review link.
        $this->actingAsApi($me)->getJson('/api/v1/portal/nps')->assertOk()->assertJsonPath('data.prompt.reference', $first->reference);
        $this->actingAsApi($me)->postJson("/api/v1/portal/trips/{$first->reference}/nps", ['score' => 10])->assertCreated()
            ->assertJsonPath('data.reviewUrl', 'https://facebook.com/bhabaghure')->assertJsonPath('data.followUp', false);
        $this->actingAsApi($me)->getJson('/api/v1/portal/nps')->assertOk()->assertJsonPath('data.prompt', null);

        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson("/api/v1/admin/customers/{$me->id}")->assertOk()->assertJsonPath('data.nps.0.score', 10)->assertJsonPath('data.nps.1.score', 4);
        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$second->id}")->assertOk()->assertJsonPath('data.nps.score', 4);

        // Only a completed trip of their own.
        $upcoming = $this->websiteBooking('01711-000001', 20);
        $this->actingAsApi($me)->postJson("/api/v1/portal/trips/{$upcoming->reference}/nps", ['score' => 8])->assertNotFound();
    }

    #[Test]
    public function staff_see_portal_status_and_turning_sign_in_off_ends_the_session_at_once(): void
    {
        $me = $this->customer(['phone' => '8801711000001', 'locale' => 'en']);
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '01711000001'])->assertAccepted();
        $signIn = $this->postJson('/api/v1/customer/auth/verify', ['phone' => '01711000001', 'code' => $this->lastSms('8801711000001')])->assertOk();
        $token = $signIn->json('access_token');

        $agent = $this->staff('sales_agent');
        $admin = $this->staff('admin');
        $portal = $this->actingAsApi($admin)->getJson("/api/v1/admin/customers/{$me->id}")->assertOk()->json('data.portal');
        $this->assertNotNull($portal['claimed_at']);
        $this->assertSame(['auth.customer.portal_claimed'], array_column($portal['sign_ins'], 'action'));
        $this->assertStringContainsString('http://customer.localhost:3000', $portal['invite_text']);
        $this->assertStringContainsString('Sign in with this mobile number', $portal['invite_text']);

        // A sales agent can't reach a customer who isn't theirs.
        $this->actingAsApi($agent)->postJson("/api/v1/admin/customers/{$me->id}/portal-access", ['enabled' => false])->assertNotFound();
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/customers/{$me->id}/portal-access", ['enabled' => false])->assertForbidden();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/customers/{$me->id}/portal-access", ['enabled' => false])->assertOk()->assertJsonPath('data.portal.disabled_at', fn ($v) => $v !== null);
        $this->assertSame(0, RefreshToken::query()->where('guard', 'customer')->where('subject_id', $me->id)->whereNull('revoked_at')->count());
        $this->resetAuthState();
        $this->withToken($token)->getJson('/api/v1/portal/trips')->assertUnauthorized();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/customers/{$me->id}/portal-access", ['enabled' => true])->assertOk()->assertJsonPath('data.portal.disabled_at', null);
        $this->assertSame(['customer.portal_enabled', 'customer.portal_disabled', 'auth.customer.portal_claimed'],
            array_column($this->actingAsApi($admin)->getJson("/api/v1/admin/customers/{$me->id}")->json('data.portal.sign_ins'), 'action'));
        $this->assertTrue(RefreshTokens::cookieName('customer') !== '');
    }

    private function lastSms(string $phone): string
    {
        $messages = array_filter(FakeSmsGateway::$sent, fn (array $m) => str_contains($m['to'], substr($phone, -10)));
        preg_match('/\d{6}/', (string) end($messages)['text'], $match);

        return $match[0];
    }

    private function completedTrip(int $endedDaysAgo): Booking
    {
        $booking = $this->websiteBooking('01711-000001', 30);
        DB::table('bookings')->where('id', $booking->id)->update([
            'status' => 'completed', 'completed_at' => now(),
            'travel_start' => now('Asia/Dhaka')->subDays($endedDaysAgo + 7)->toDateString(), 'travel_end' => now('Asia/Dhaka')->subDays($endedDaysAgo)->toDateString(),
        ]);

        return $booking->fresh('customer');
    }

    private function websiteBooking(string $phone, int $daysAhead): Booking
    {
        $reference = $this->postJson('/api/v1/public/bookings', [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays($daysAhead)->toDateString(),
            'pax' => 1,
            'room' => 'single',
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2031-01-31', 'phone' => $phone, 'email' => null]],
            'expected_total' => 85680,
            'terms_accepted' => true,
            'locale' => 'en',
        ])->assertCreated()->json('data.reference');

        return Booking::query()->with('customer')->where('reference', $reference)->firstOrFail();
    }
}
