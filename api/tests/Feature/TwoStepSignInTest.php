<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Services\Auth\RefreshTokens;
use App\Services\Auth\TwoFactor;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/two-step-sign-in.md: an authenticator app's code after the password, for the admin and the wallet. */
class TwoStepSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    #[Test]
    public function a_person_sets_it_up_from_their_profile_and_the_secret_and_codes_are_shown_once_and_kept_safe(): void
    {
        $agent = $this->staff('sales_agent');
        $this->actingAsApi($agent)->getJson('/api/v1/staff/auth/two-factor')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.required', false);

        $setup = $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/setup')->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['secret']);
        $this->assertStringStartsWith('otpauth://totp/Bhabaghure:'.rawurlencode($agent->email).'?secret='.$setup['secret'], $setup['uri']);
        // Not in force until a code from it is confirmed: the password alone still signs in.
        $this->login($agent)->assertOk();

        $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable()
            ->assertJsonPath('code', 'two_factor_invalid_code');
        $confirmed = $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/confirm', ['code' => $this->code($setup['secret'])])->assertOk();
        $confirmed->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_left', 10);
        $codes = $confirmed->json('data.recovery_codes');
        $this->assertCount(10, array_unique($codes));
        $this->assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}$/', $codes[0]);

        // Neither the secret nor the codes are anywhere else: not readable in the database, not in any answer.
        $row = DB::table('staff')->where('id', $agent->id)->first();
        $this->assertStringNotContainsString($setup['secret'], (string) $row->two_factor_secret);
        $this->assertStringNotContainsString($codes[0], (string) $row->two_factor_recovery_codes);
        $me = $this->actingAsApi($agent)->getJson('/api/v1/staff/auth/me')->assertOk()->assertJsonPath('two_factor_enabled', true)->getContent();
        $this->assertStringNotContainsString($setup['secret'], $me);
        $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/setup')->assertUnprocessable()->assertJsonPath('code', 'two_factor_already_on');
        $this->assertTrue(AuditLog::query()->where('action', 'auth.two_factor.enabled')->where('auditable_id', $agent->id)->exists());
    }

    #[Test]
    public function sign_in_asks_for_the_code_after_the_password_and_each_code_and_recovery_code_works_once(): void
    {
        [$agent, $secret, $codes] = $this->withTwoFactor($this->staff('sales_agent'));

        // A wrong password says nothing about the code; the right one asks for it, and issues no token yet.
        $this->login($agent, password: 'wrong')->assertUnauthorized()->assertJsonMissingPath('access_token');
        $this->login($agent)->assertUnauthorized()->assertJsonPath('code', 'two_factor_required')->assertJsonMissingPath('access_token');
        $this->login($agent, code: '123456')->assertUnauthorized()->assertJsonPath('code', 'two_factor_invalid');

        $this->travel(31)->seconds();
        $code = $this->code($secret);
        $this->login($agent, code: $code)->assertOk()->assertJsonStructure(['access_token'])->assertJsonPath('staff.two_factor_enabled', true);
        $this->assertSame(['two_factor' => 'code'], AuditLog::query()->where('action', 'auth.staff.login')->latest('id')->value('changes'));
        // The same code again, a second later: refused, even inside its 30 seconds.
        $this->login($agent, code: $code)->assertUnauthorized()->assertJsonPath('code', 'two_factor_invalid');

        // A recovery code, typed in capitals without its dash, works once and says how many are left.
        $typed = strtoupper(str_replace('-', '', $codes[3]));
        $this->login($agent, code: $typed)->assertOk()->assertJsonPath('recovery_codes_left', 9);
        $this->login($agent, code: $typed)->assertUnauthorized();
    }

    #[Test]
    public function too_many_wrong_codes_lock_the_account_whatever_address_they_come_from(): void
    {
        [$agent, $secret] = $this->withTwoFactor($this->staff('sales_agent'));

        foreach (range(1, TwoFactor::FAILURES_ALLOWED) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->login($agent, code: '000000');
        }
        // Even the right code waits now, from a fresh address.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);
        $this->login($agent, code: $this->code($secret, 31))->assertTooManyRequests()->assertJsonPath('code', 'two_factor_locked');
        $this->travel(TwoFactor::FAILURE_WINDOW_SECONDS + 1)->seconds();
        $this->login($agent, code: $this->code($secret))->assertOk();
    }

    #[Test]
    public function turning_it_off_takes_the_password_and_a_code(): void
    {
        [$agent, $secret] = $this->withTwoFactor($this->staff('sales_agent'));
        $off = '/api/v1/staff/auth/two-factor/disable';

        $this->actingAsApi($agent)->postJson($off, ['password' => 'wrong', 'code' => $this->code($secret, 31)])->assertUnprocessable()->assertJsonPath('code', 'two_factor_wrong_password');
        $this->actingAsApi($agent)->postJson($off, ['password' => self::PASSWORD, 'code' => '000000'])->assertUnprocessable()->assertJsonPath('code', 'two_factor_invalid_code');
        $this->actingAsApi($agent)->postJson($off, ['password' => self::PASSWORD, 'code' => $this->code($secret, 31)])->assertOk()->assertJsonPath('data.enabled', false);
        $this->login($agent)->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'auth.two_factor.disabled')->exists());
    }

    #[Test]
    public function the_super_admin_can_require_it_and_then_everyone_sets_it_up_before_anything_else(): void
    {
        $owner = $this->staff('super_admin');
        $admin = $this->staff('admin');
        $agent = $this->staff('sales_agent');
        $this->staff(null, ['software_access' => false, 'email' => null]);

        $this->actingAsApi($admin)->getJson('/api/v1/admin/security')->assertForbidden();
        $this->actingAsApi($admin)->putJson('/api/v1/admin/security', ['two_factor_required' => true])->assertForbidden();
        [$owner] = $this->withTwoFactor($owner);
        $this->actingAsApi($owner)->putJson('/api/v1/admin/security', ['two_factor_required' => true])->assertOk()
            ->assertJsonPath('data.two_factor_required', true)->assertJsonPath('data.with_login', 3)->assertJsonPath('data.two_factor_on', 1);
        $this->assertTrue(TwoFactor::required());
        // Never in the website's public settings.
        $this->assertStringNotContainsString('two_factor', $this->getJson('/api/v1/public/settings')->getContent());

        // The agent signs in with the password, and can do nothing but set it up.
        $this->login($agent)->assertOk()->assertJsonPath('staff.two_factor_setup_required', true);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/bookings')->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
        $setup = $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/setup')->assertOk()->json('data');
        $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/confirm', ['code' => $this->code($setup['secret'])])->assertOk();
        $this->actingAsApi($agent->refresh())->getJson('/api/v1/admin/bookings')->assertOk();
        // While it is required, nobody turns their own off.
        $this->actingAsApi($agent)->postJson('/api/v1/staff/auth/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($setup['secret'], 31)])
            ->assertUnprocessable()->assertJsonPath('code', 'two_factor_required_by_policy');
    }

    #[Test]
    public function the_super_admin_resets_a_lost_phone_and_the_person_is_signed_out_everywhere(): void
    {
        $owner = $this->staff('super_admin');
        $admin = $this->staff('admin');
        [$agent] = $this->withTwoFactor($this->staff('sales_agent'));
        $refresh = $this->login($agent, code: $this->code($agent->two_factor_secret, 31))->assertOk()->getCookie(RefreshTokens::cookieName('staff'), false)?->getValue();
        $this->assertNotNull($refresh);

        $this->actingAsApi($admin)->getJson("/api/v1/admin/staff/{$agent->id}")->assertOk()->assertJsonPath('data.two_factor_enabled', true)->assertJsonPath('data.actions.reset_two_factor', false);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/staff/{$agent->id}/two-factor/reset")->assertForbidden();
        $this->actingAsApi($owner)->getJson("/api/v1/admin/staff/{$agent->id}")->assertJsonPath('data.actions.reset_two_factor', true);
        $this->actingAsApi($owner)->postJson("/api/v1/admin/staff/{$owner->id}/two-factor/reset")->assertForbidden();

        $this->actingAsApi($owner)->postJson("/api/v1/admin/staff/{$agent->id}/two-factor/reset")->assertOk()->assertJsonPath('data.two_factor_enabled', false);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.two_factor.reset')->where('auditable_id', $agent->id)->exists());
        // Their sessions are gone; they sign in with the password alone, and set it up again if they want.
        $this->withCredentials()->withUnencryptedCookie(RefreshTokens::cookieName('staff'), $refresh)->postJson('/api/v1/staff/auth/refresh')->assertUnauthorized();
        $this->login($agent)->assertOk();
    }

    /** @return array{0: Staff, 1: string, 2: list<string>} */
    private function withTwoFactor(Staff $staff): array
    {
        $setup = $this->actingAsApi($staff)->postJson('/api/v1/staff/auth/two-factor/setup')->assertOk()->json('data');
        $codes = $this->actingAsApi($staff)->postJson('/api/v1/staff/auth/two-factor/confirm', ['code' => $this->code($setup['secret'])])->assertOk()->json('data.recovery_codes');
        $this->flushHeaders();
        $this->resetAuthState();

        return [$staff->refresh(), $setup['secret'], $codes];
    }

    /** The code an authenticator app shows now, or this many seconds from now (so it falls in a step not used yet). */
    private function code(string $secret, int $later = 0): string
    {
        if ($later > 0) {
            $this->travel($later)->seconds();
        }

        return Totp::code($secret, Totp::step(now()->getTimestamp()));
    }

    private function login(Staff $staff, string $password = self::PASSWORD, ?string $code = null)
    {
        $this->flushHeaders();
        $this->resetAuthState();

        return $this->postJson('/api/v1/staff/auth/login', array_filter(['email' => $staff->email, 'password' => $password, 'code' => $code]));
    }
}
