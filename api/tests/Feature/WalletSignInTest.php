<?php

namespace Tests\Feature;

use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Session;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesWalletDatabase;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8, docs/personal-wallets.md: a wallet opens with its owner's staff password
 * (no authenticator code since 2026-10-09) in a session of its own, only on its own host, only from its own page.
 */
class WalletSignInTest extends TestCase
{
    use UsesWalletDatabase;

    protected $connectionsToTransact = [null, 'wallet'];

    #[Test]
    public function the_password_opens_a_wallet_session_of_its_own_and_sign_out_ends_it(): void
    {
        $owner = $this->staff('super_admin');

        // No authenticator code since 2026-10-09 (docs/personal-wallets.md): the password opens the session.
        $this->wallet('POST', 'auth/password', ['email' => $owner->email, 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $signedIn = $this->wallet('POST', 'auth/password', ['email' => $owner->email, 'password' => 'correct-horse-battery'])->assertOk()
            ->assertJsonPath('data.signed_in', true);
        $cookie = $signedIn->getCookie('bh_wallet', false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/wallet', $cookie->getPath());
        // Only its hash is stored.
        $this->assertSame(hash('sha256', $cookie->getValue()), Session::query()->sole()->token_hash);
        $this->wallet('GET', 'auth/me', session: $cookie->getValue())->assertOk()->assertJsonPath('data.email', $owner->email);

        // Sign-out ends the session.
        $this->wallet('POST', 'auth/sign-out', session: $cookie->getValue())->assertOk();
        $this->wallet('GET', 'summary', session: $cookie->getValue())->assertUnauthorized()->assertJsonPath('code', 'signed_out');
        $this->assertSame(['sign_in_refused', 'signed_in', 'signed_out'], AuditLog::query()->orderBy('id')->pluck('action')->all());
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
}
