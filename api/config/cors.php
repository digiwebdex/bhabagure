<?php

/*
 * The website posts forms and customer sign-in straight to the API (so the rate limiter sees the
 * visitor's IP), and the admin SPA calls it with the refresh cookie. Only those origins are allowed.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Locale', 'X-Requested-With', 'X-Booking-Token'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    // Refresh tokens travel in an httpOnly cookie.
    'supports_credentials' => true,

];
