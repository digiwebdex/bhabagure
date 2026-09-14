<?php

use App\Models\Customer;
use App\Models\Staff;

/*
 * Two separate guards (docs/phase-1-schema.md §6). Each JWT carries a `prv` claim naming its model and
 * jwt.lock_subject is on, so a customer token is rejected on staff routes and vice versa.
 */
return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'staff'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'staff'),
    ],

    'guards' => [
        'staff' => [
            'driver' => 'jwt',
            'provider' => 'staff',
        ],
        'customer' => [
            'driver' => 'jwt',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'staff' => [
            'driver' => 'eloquent',
            'model' => Staff::class,
        ],
        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],
    ],

    'passwords' => [
        'staff' => [
            'provider' => 'staff',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
     * Refresh tokens: opaque, stored hashed in auth_refresh_tokens, rotated on every use, sent as an
     * httpOnly cookie scoped to the guard's auth path on the API host.
     */
    'refresh' => [
        'ttl_days' => (int) env('AUTH_REFRESH_TTL_DAYS', 14),
        // A token rotated this recently, presented again by the same browser (IP and user agent), gets the same
        // successor: a reload during a refresh or a double mount is not an attack. Later, or from anywhere else, it
        // is treated as theft and the whole sign-in is revoked.
        'reuse_grace_seconds' => (int) env('AUTH_REFRESH_REUSE_GRACE_SECONDS', 10),
        'cookie_secure' => (bool) env('AUTH_REFRESH_COOKIE_SECURE', true),
        'cookie_domain' => env('AUTH_REFRESH_COOKIE_DOMAIN'),
    ],

];
