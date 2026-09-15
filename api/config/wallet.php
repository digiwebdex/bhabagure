<?php

/*
 * The super admin wallet (docs/phase-7-hr-attendance-bonus-wallet.md §8): the proprietor's private cash account,
 * separate from the company books. Its database connection is `wallet` in config/database.php.
 */
return [
    // The only host its API answers on (wallet.bhabaghure.com.bd). Empty is allowed outside production only.
    'host' => env('WALLET_HOST', ''),

    // Encrypts the second-factor secret and evidence files. Not APP_KEY, so company-side code can't read them.
    'key' => env('WALLET_KEY', ''),

    'session' => [
        'cookie' => 'bh_wallet',
        'secure' => (bool) env('WALLET_COOKIE_SECURE', true),
        // Signed out after this long without a request, and after this long in all.
        'idle_minutes' => (int) env('WALLET_IDLE_MINUTES', 30),
        'absolute_minutes' => (int) env('WALLET_ABSOLUTE_MINUTES', 720),
        // Between the password and the authenticator code.
        'challenge_minutes' => 5,
    ],

    'evidence' => [
        'disk' => 'local',
        'directory' => 'wallet/evidence',
    ],

    // The name the authenticator app shows.
    'issuer' => env('WALLET_ISSUER', 'Bhabaghure wallet'),
];
