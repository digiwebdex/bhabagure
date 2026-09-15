<?php

namespace Tests\Feature;

use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Authenticator;
use App\Wallet\Models\Session;
use App\Wallet\Support\Totp;
use Illuminate\Contracts\Encryption\DecryptException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesWalletDatabase;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8: the wallet opens with the super admin's password and an authenticator
 * code — enrolled on first use — in a session of its own, only on its own host, only from its own page.
 */
class WalletSignInTest extends TestCase
{
    use UsesWalletDatabase;

    protected $connectionsToTransact = [null, 'wallet'];

    #[Test]
    public function the_super_admin_enrolls_an_authenticator_on_first_sign_in_and_each_code_works_once(): void
    {
        $owner = $this->staff('super_admin');

        $first = $this->wallet('POST', 'auth/password', ['email' => $owner->email, 'password' => 'correct-horse-battery'])->assertOk()
            ->assertJsonPath('data.enrolled', false);
        $secret = $first->json('data.enrollment.secret');
        $this->assertStringStartsWith('otpauth://totp/', $first->json('data.enrollment.uri'));
        // The secret is stored encrypted with WALLET_KEY, not in the clear and not with APP_KEY.
        $stored = Authenticator::query()->where('staff_id', $owner->id)->sole();
        $this->assertStringNotContainsString($secret, $stored->getRawOriginal('secret'));
        $this->assertSame($secret, $stored->plainSecret());
        $this->expectsNoAppKeyDecryption($stored->getRawOriginal('secret'));

        // A wrong code is refused and counted; the right one enrolls the app and opens a session.
        $this->wallet('POST', 'auth/code', ['challenge' => $first->json('data.challenge'), 'code' => '000000'])->assertUnauthorized()->assertJsonPath('code', 'invalid_code');
        $this->assertSame(1, Session::query()->where('kind', 'challenge')->value('failed_codes'));
        $code = Totp::code($secret, Totp::step(now()->getTimestamp()));
        $signedIn = $this->wallet('POST', 'auth/code', ['challenge' => $first->json('data.challenge'), 'code' => $code])->assertOk();
        $cookie = $signedIn->getCookie('bh_wallet', false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/wallet', $cookie->getPath());
        $this->assertNotNull($stored->fresh()->confirmed_at);
        $this->wallet('GET', 'auth/me', session: $cookie->getValue())->assertOk()->assertJsonPath('data.email', $owner->email);

        // Enrolled now: no secret is shown again, and the same code can't be used twice.
        $second = $this->wallet('POST', 'auth/password', ['email' => $owner->email, 'password' => 'correct-horse-battery'])->assertOk()
            ->assertJsonPath('data.enrolled', true)->assertJsonPath('data.enrollment', null);
        $this->wallet('POST', 'auth/code', ['challenge' => $second->json('data.challenge'), 'code' => $code])->assertUnauthorized()->assertJsonPath('code', 'invalid_code');

        // Sign-out ends the session.
        $this->wallet('POST', 'auth/sign-out', session: $cookie->getValue())->assertOk();
        $this->wallet('GET', 'summary', session: $cookie->getValue())->assertUnauthorized()->assertJsonPath('code', 'signed_out');
        $this->assertSame(['sign_in_refused', 'authenticator_enrolled', 'signed_in', 'sign_in_refused', 'signed_out'], AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function nobody_but_an_active_super_admin_gets_in_and_the_admins_token_never_opens_the_wallet(): void
    {
        $admin = $this->staff('admin');
        $owner = $this->staff('super_admin');

        $this->wallet('POST', 'auth/password', ['email' => $admin->email, 'password' => 'correct-horse-battery'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->wallet('POST', 'auth/password', ['email' => $owner->email, 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->wallet('POST', 'auth/password', ['email' => 'nobody@example.test', 'password' => 'correct-horse-battery'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        // The super admin's admin access token is not a wallet session.
        $this->actingAsApi($owner)->getJson('/api/v1/wallet/summary', ['X-Wallet-Request' => '1'])->assertUnauthorized();

        // A session ends when the account is suspended, after 30 idle minutes, and after 12 hours in all.
        $session = $this->walletSignIn($owner->email);
        $this->wallet('GET', 'summary', session: $session)->assertOk();
        $this->travel(31)->minutes();
        $this->wallet('GET', 'summary', session: $session)->assertUnauthorized();

        $session = $this->walletSignIn($owner->email);
        $owner->forceFill(['status' => 'suspended'])->save();
        $this->wallet('GET', 'summary', session: $session)->assertUnauthorized();
    }

    #[Test]
    public function the_wallet_answers_only_on_its_own_host_only_from_its_own_page_and_fails_closed_in_production(): void
    {
        $owner = $this->staff('super_admin');
        $session = $this->walletSignIn($owner->email);

        // Without the wallet page's header, or from another origin (the admin shares the site): refused.
        $this->withCredentials()->withUnencryptedCookie('bh_wallet', $session)->getJson('/api/v1/wallet/summary')->assertForbidden()->assertJsonPath('code', 'header_missing');
        $this->withCredentials()->withUnencryptedCookie('bh_wallet', $session)->getJson('/api/v1/wallet/summary', ['X-Wallet-Request' => '1', 'Origin' => 'https://admin.bhabaghure.com.bd'])
            ->assertForbidden()->assertJsonPath('code', 'foreign_origin');

        // With a host configured, another host doesn't have the wallet at all.
        config(['wallet.host' => 'wallet.bhabaghure.com.bd']);
        $this->withCredentials()->withUnencryptedCookie('bh_wallet', $session)->getJson('http://api.bhabaghure.com.bd/api/v1/wallet/summary', ['X-Wallet-Request' => '1'])->assertNotFound();
        $this->withCredentials()->withUnencryptedCookie('bh_wallet', $session)->getJson('http://wallet.bhabaghure.com.bd/api/v1/wallet/summary', ['X-Wallet-Request' => '1'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        // Production with no host set: closed.
        config(['wallet.host' => '']);
        $this->app['env'] = 'production';
        $this->withCredentials()->withUnencryptedCookie('bh_wallet', $session)->getJson('/api/v1/wallet/summary', ['X-Wallet-Request' => '1'])->assertNotFound();
        $this->app['env'] = 'testing';
    }

    private function expectsNoAppKeyDecryption(string $payload): void
    {
        try {
            decrypt($payload, false);
            $this->fail('The authenticator secret opened with APP_KEY.');
        } catch (DecryptException) {
            $this->addToAssertionCount(1);
        }
    }
}
