<?php

namespace App\Wallet;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** The wallet's own wiring (docs/phase-7-hr-attendance-bonus-wallet.md §8), kept out of the company's providers. */
final class WalletServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Passwords and codes: a handful a minute per address, and per email, whatever the address.
        RateLimiter::for('wallet-sign-in', fn (Request $request) => [
            Limit::perMinute(6)->by('wallet-sign-in|'.$request->ip()),
            Limit::perMinute(10)->by('wallet-sign-in-email|'.mb_strtolower((string) $request->input('email', 'code'))),
        ]);
        RateLimiter::for('wallet', fn (Request $request) => Limit::perMinute(240)->by('wallet|'.$request->ip()));
    }
}
