<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * For tests that touch the wallet: RefreshDatabase for the company database, plus bhabaghure_wallet_testing built from
 * its own migrations once per test class, and requests sent the way the wallet page sends them (its header, its
 * cookie). Declare `protected $connectionsToTransact = [null, 'wallet'];` so each test rolls both databases back.
 */
trait UsesWalletDatabase
{
    use RefreshDatabase;

    private static bool $walletSchemaBuilt = false;

    protected function beforeRefreshingDatabase(): void
    {
        if (! self::$walletSchemaBuilt) {
            Artisan::call('migrate:fresh', ['--database' => 'wallet', '--path' => 'database/migrations/wallet', '--force' => true]);
            self::$walletSchemaBuilt = true;
        }
    }

    /** @param array<string, mixed> $data */
    protected function wallet(string $method, string $uri, array $data = [], ?string $session = null): TestResponse
    {
        $this->resetAuthState();
        $headers = ['X-Wallet-Request' => '1', 'Accept' => 'application/json'];
        // JSON test requests carry cookies only with credentials, as a browser's fetch would.
        $request = $session === null ? $this : $this->withCredentials()->withUnencryptedCookie('bh_wallet', $session);

        return $request->json($method, "/api/v1/wallet/{$uri}", $data, $headers);
    }

    /** Signs a staff member in with their password (no authenticator code since 2026-10-09) and returns the session cookie. */
    protected function walletSignIn(string $email, string $password = 'correct-horse-battery'): string
    {
        $response = $this->wallet('POST', 'auth/password', ['email' => $email, 'password' => $password])->assertOk();

        return (string) $response->getCookie('bh_wallet', false)?->getValue();
    }
}
