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

    // Content-Disposition: the admin (another origin) saves a document under the name the API gives it, INV-1065.pdf.
    'exposed_headers' => ['Retry-After', 'Content-Disposition'],

    'max_age' => 3600,

    // Refresh tokens travel in an httpOnly cookie.
    'supports_credentials' => true,

];
