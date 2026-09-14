<?php

namespace Tests\Feature;

use App\Enums\StaffStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\RefreshToken;
use App\Services\Auth\RefreshTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_sign_in_returns_a_short_lived_token_and_an_httponly_refresh_cookie(): void
    {
        $staff = $this->staff('tour_operator');

        $response = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 900)
            ->assertJsonPath('staff.role', 'tour_operator')
            ->assertJsonPath('staff.permissions', fn (array $permissions) => in_array('packages.manage', $permissions, true));

        $cookie = $this->refreshCookie($response, 'staff');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/staff/auth', $cookie->getPath());

        $this->withToken($response->json('access_token'))->getJson('/api/v1/staff/auth/me')->assertOk()->assertJsonPath('email', $staff->email);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.staff.login')->exists());
    }

    #[Test]
    public function failed_sign_ins_are_audited_and_throttled_after_five_attempts(): void
    {
        $staff = $this->staff();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])->assertTooManyRequests();

        $this->assertSame(5, AuditLog::query()->where('action', 'auth.staff.login_failed')->count());
    }

    #[Test]
    public function a_customer_token_is_rejected_on_staff_routes_and_vice_versa(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();

        $this->actingAsApi($customer)->getJson('/api/v1/staff/auth/me')->assertUnauthorized();
        $this->actingAsApi($customer)->getJson('/api/v1/admin/packages')->assertUnauthorized();
        $this->actingAsApi($staff)->getJson('/api/v1/customer/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function refresh_rotates_the_token_and_reusing_an_old_one_ends_the_whole_session(): void
    {
        $staff = $this->staff();
        $login = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
        $first = $this->refreshCookie($login, 'staff')->getValue();

        $refreshed = $this->refresh('staff', $first)->assertOk();
        $second = $this->refreshCookie($refreshed, 'staff')->getValue();
        $this->assertNotSame($first, $second);

        // Someone replays the first token later: refused, and the legitimate second token dies with it.
        $this->travel(11)->seconds();
        $this->refresh('staff', $first)->assertUnauthorized()->assertJsonPath('code', 'session_expired');
        $this->refresh('staff', $second)->assertUnauthorized();
    }

    #[Test]
    public function the_same_browser_refreshing_twice_in_quick_succession_keeps_its_session(): void
    {
        $staff = $this->staff();
        $login = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
        $first = $this->refreshCookie($login, 'staff')->getValue();

        // A reload while the first refresh is in flight: both requests carry the first token.
        $second = $this->refreshCookie($this->refresh('staff', $first)->assertOk(), 'staff')->getValue();
        $this->travel(3)->seconds();
        $again = $this->refresh('staff', $first)->assertOk()->assertJsonStructure(['access_token']);
        $this->assertSame($second, $this->refreshCookie($again, 'staff')->getValue(), 'the same successor, not a new family');

        // A third quick request with the first token, after the second token has itself rotated, follows the chain.
        $third = $this->refreshCookie($this->refresh('staff', $second)->assertOk(), 'staff')->getValue();
        $this->assertSame($third, $this->refreshCookie($this->refresh('staff', $first)->assertOk(), 'staff')->getValue());

        // The session carries on normally afterwards.
        $this->travel(30)->seconds();
        $this->refresh('staff', $third)->assertOk();
        $this->assertSame(1, RefreshToken::query()->distinct()->count('family'));
    }

    #[Test]
    public function a_replay_from_another_device_even_within_seconds_ends_the_session(): void
    {
        $staff = $this->staff();
        $login = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
        $first = $this->refreshCookie($login, 'staff')->getValue();
        $second = $this->refreshCookie($this->refresh('staff', $first)->assertOk(), 'staff')->getValue();

        $this->resetAuthState();
        $this->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName('staff'), $first)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Other device')
            ->postJson('/api/v1/staff/auth/refresh')->assertUnauthorized();
        $this->refresh('staff', $second)->assertUnauthorized();

        // Another IP, same user agent: the same.
        $login = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
        $first = $this->refreshCookie($login, 'staff')->getValue();
        $second = $this->refreshCookie($this->refresh('staff', $first)->assertOk(), 'staff')->getValue();
        $this->resetAuthState();
        $this->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName('staff'), $first)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/staff/auth/refresh')->assertUnauthorized();
        $this->refresh('staff', $second)->assertUnauthorized();
    }

    #[Test]
    public function a_signed_out_session_is_not_revived_by_the_grace_window(): void
    {
        $staff = $this->staff();
        $login = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
        $first = $this->refreshCookie($login, 'staff')->getValue();
        $refreshed = $this->refresh('staff', $first)->assertOk();
        $second = $this->refreshCookie($refreshed, 'staff')->getValue();

        $this->resetAuthState();
        $this->withToken($refreshed->json('access_token'))->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName('staff'), $second)
            ->postJson('/api/v1/staff/auth/logout')->assertNoContent();

        $this->refresh('staff', $first)->assertUnauthorized();
        $this->refresh('staff', $second)->assertUnauthorized();
    }

    #[Test]
    public function logout_revokes_the_refresh_token_and_blacklists_the_access_token(): void
    {
        $staff = $this->staff();
        $login = $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery']);
        $token = $login->json('access_token');
        $refresh = $this->refreshCookie($login, 'staff')->getValue();

        $this->resetAuthState();
        $this->withToken($token)->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName('staff'), $refresh)
            ->postJson('/api/v1/staff/auth/logout')->assertNoContent();

        $this->resetAuthState();
        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertUnauthorized();
        $this->refresh('staff', $refresh)->assertUnauthorized();
    }

    #[Test]
    public function staff_who_must_change_their_password_can_only_do_that(): void
    {
        $staff = $this->staff('admin', ['must_change_password' => true]);

        $this->actingAsApi($staff)->getJson('/api/v1/admin/packages')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->actingAsApi($staff)->postJson('/api/v1/staff/auth/change-password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertOk()->assertJsonPath('staff.must_change_password', false);

        $this->actingAsApi($staff->fresh())->getJson('/api/v1/admin/packages')->assertOk();
    }

    #[Test]
    public function a_suspended_staff_member_is_locked_out_even_with_a_valid_token(): void
    {
        $staff = $this->staff();
        $token = $this->app->make(JWT::class)->fromSubject($staff);
        $staff->forceFill(['status' => StaffStatus::Suspended])->save();

        $this->resetAuthState();
        $this->withToken($token)->getJson('/api/v1/admin/packages')->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])->assertForbidden();
    }

    #[Test]
    public function access_tokens_are_not_accepted_from_the_query_string(): void
    {
        $token = $this->app->make(JWT::class)->fromSubject($this->staff());

        $this->getJson('/api/v1/staff/auth/me?token='.$token)->assertUnauthorized();
    }

    #[Test]
    public function customers_register_and_sign_in_with_phone_in_any_common_format_or_email(): void
    {
        $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Sadia Rahman', 'phone' => '01711-000123', 'email' => 'Sadia@Example.test', 'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('customer.name', 'Sadia Rahman')->assertJsonPath('customer.phone', '8801711000123');

        $this->assertSame('sadia@example.test', Customer::query()->value('email'));

        foreach (['01711000123', '+8801711000123', '০১৭১১০০০১২৩', 'sadia@example.test'] as $identifier) {
            $this->resetAuthState();
            $this->postJson('/api/v1/customer/auth/login', ['identifier' => $identifier, 'password' => 'secret123'])
                ->assertOk()->assertJsonPath('customer.name', 'Sadia Rahman');
        }
    }

    #[Test]
    public function a_phone_or_email_already_on_file_gets_a_neutral_contact_us_answer(): void
    {
        $this->customer(['phone' => '8801711000123', 'email' => 'known@example.test', 'password' => null]);

        $phone = $this->withHeader('X-Locale', 'en')
            ->postJson('/api/v1/customer/auth/register', ['name' => 'Someone else', 'phone' => '01711000123', 'email' => '', 'password' => 'secret123'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'To open an account with this number, please contact us.', 'code' => 'contact_us', 'field' => 'phone']);
        foreach (['customer', 'registered', 'already', 'exists', 'taken'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $phone->json('message'));
        }

        $this->withHeader('X-Locale', 'bn')->postJson('/api/v1/customer/auth/register', ['name' => 'Someone else', 'phone' => '01711000999', 'email' => 'Known@Example.test', 'password' => 'secret123'])
            ->assertStatus(409)->assertJsonPath('field', 'email')->assertJsonPath('message', 'এই ইমেইল দিয়ে অ্যাকাউন্ট খুলতে আমাদের সাথে যোগাযোগ করুন।');

        $this->assertSame(1, Customer::query()->count());
    }

    #[Test]
    public function the_contact_us_answer_only_comes_after_the_form_is_otherwise_valid(): void
    {
        $this->customer(['phone' => '8801711000123']);

        $this->postJson('/api/v1/customer/auth/register', ['name' => 'X', 'phone' => '01711000123', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonMissingValidationErrors('phone');
    }

    #[Test]
    public function customer_passwords_need_at_least_eight_characters(): void
    {
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Sadia', 'phone' => '01711000555', 'password' => 'seven77'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Sadia', 'phone' => '01711000555', 'password' => 'eight888'])
            ->assertCreated();
    }

    #[Test]
    public function a_customer_created_by_staff_without_a_password_cannot_sign_in(): void
    {
        $this->customer(['phone' => '8801711000123', 'password' => null]);

        $this->postJson('/api/v1/customer/auth/login', ['identifier' => '01711000123', 'password' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/customer/auth/login', ['identifier' => '01711000123', 'password' => 'anything'])->assertUnauthorized();
    }

    #[Test]
    public function errors_are_in_bangla_by_default_and_english_on_request(): void
    {
        $this->postJson('/api/v1/customer/auth/login', ['identifier' => 'nobody@example.test', 'password' => 'x'])
            ->assertUnauthorized()->assertJsonPath('message', 'এই তথ্যের সাথে কোনো অ্যাকাউন্ট মেলেনি।');

        $this->withHeader('X-Locale', 'en')->postJson('/api/v1/customer/auth/login', ['identifier' => 'nobody@example.test', 'password' => 'x'])
            ->assertUnauthorized()->assertJsonPath('message', 'These credentials do not match our records.');
    }

    private function refresh(string $guard, string $token): TestResponse
    {
        $this->resetAuthState();

        return $this->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName($guard), $token)->postJson("/api/v1/{$guard}/auth/refresh");
    }

    private function refreshCookie(TestResponse $response, string $guard): Cookie
    {
        $cookie = $response->getCookie(RefreshTokens::cookieName($guard), decrypt: false);
        $this->assertNotNull($cookie, 'refresh cookie missing');

        return $cookie;
    }
}
