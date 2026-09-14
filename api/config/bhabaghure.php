<?php

return [

    /*
     * Bilingual seed content shared with the website (packages/content-seed). The seeders read it directly,
     * so the website on seed data and the API after seeding show the same content.
     */
    'content_seed_path' => env('CONTENT_SEED_PATH', base_path('../packages/content-seed')),

    /*
     * After a CMS save the API asks the website to refresh the affected cache tags
     * (web/src/app/api/revalidate/route.ts). Empty URL = skip, e.g. in local work without the website.
     */
    'web_url' => env('WEB_URL', 'http://localhost:3000'),
    // The customer portal (docs/phase-6-customer-portal.md): payments started there return there; invites link to it.
    'portal_url' => env('PORTAL_URL', 'http://customer.localhost:3000'),
    // Base of short invoice links in SMS (…/i/{code}); the API host serves them.
    'short_link_base' => env('SHORT_LINK_BASE') ?: env('APP_URL', 'http://localhost:8000'),
    'web_revalidate_url' => env('WEB_REVALIDATE_URL'),
    'revalidate_secret' => env('REVALIDATE_SECRET'),

    /*
     * Optional MySQL triggers that also enforce the ledger and invoice rules for clients outside this app.
     * Off: creating them needs a server-wide MySQL setting the shared host doesn't allow. The application
     * layer enforces the same rules either way (App\Support\Database\LedgerTables). See db:guard-triggers.
     */
    'database_guard_triggers' => (bool) env('DB_GUARD_TRIGGERS', false),

    'booking' => [
        // Seats on a departure are held this long while the customer pays (docs/phase-3-booking.md §3).
        'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 45),
        // Stored with the customer's consent. Bump it when the lawyer-approved terms replace the drafts.
        'terms_version' => env('BOOKING_TERMS_VERSION', 'draft-2026-09'),
    ],

    /*
     * SSLCommerz (docs/phase-3-booking.md §4). `sandbox` and `live` call SSLCommerz; `live` is refused unless
     * APP_ENV=production. `fake` is a local stand-in (no network) for development and end-to-end tests, refused in
     * production. Store credentials live only in .env — the repository is public.
     */
    'sslcommerz' => [
        'mode' => env('SSLCOMMERZ_MODE', 'sandbox'),
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'hosts' => [
            'sandbox' => 'https://sandbox.sslcommerz.com',
            'live' => 'https://securepay.sslcommerz.com',
        ],
        'timeout_seconds' => 20,
        // Sent when the lead traveller gave no email (SSLCommerz requires one).
        'fallback_email' => env('SSLCOMMERZ_FALLBACK_EMAIL', 'info@bhabaghure.com.bd'),
    ],

    /*
     * Passport OCR (docs/phase-3-booking.md §5). `none` until the client has a cloud account: scans are still stored
     * and fields are typed by hand. `textract` uses AWS Textract with the keys below (an IAM user limited to
     * textract:DetectDocumentText). Scans are encrypted on the private disk and deleted after the retention window
     * unless a booking uses them.
     */
    'passport_ocr' => [
        'provider' => env('PASSPORT_OCR_PROVIDER', 'none'),
        'disk' => 'local',
        'retention_hours' => 24,
        'max_upload_kb' => 5120,
        'timeout_seconds' => 10,
        'aws' => [
            'region' => env('AWS_TEXTRACT_REGION', 'ap-south-1'),
            'key' => env('AWS_TEXTRACT_KEY'),
            'secret' => env('AWS_TEXTRACT_SECRET'),
        ],
    ],

    /*
     * Invoice print view and PDFs (docs/phase-3-booking.md §6). PDFs come from packages/pdf (headless Chrome).
     * PDF_CHROME_PATH: a Chrome binary — Chrome for Testing inside the project directory on the VPS, or the desktop
     * Chrome locally. Empty = Playwright's own Chromium.
     */
    'invoices' => [
        // Height of the company header block. With the header off the block stays, empty, so a pre-printed pad's
        // letterhead fits there and nothing below moves. A site setting (`invoice.headerHeightMm`) overrides it.
        'header_height_mm' => 42,
        'node_binary' => env('PDF_NODE_BINARY', 'node'),
        'renderer' => env('PDF_RENDERER', base_path('../packages/pdf/src/render.mjs')),
        'inspector' => base_path('../packages/pdf/src/inspect.mjs'),
        'chrome_path' => env('PDF_CHROME_PATH'),
        // HOME / TMPDIR for the renderer, so Chrome writes nothing outside the project.
        'chrome_home' => storage_path('app/private/chrome'),
        'timeout_seconds' => 60,
        'logo_path' => base_path('../web/public/brand/logo-wordmark.png'),
    ],

    /*
     * Notifications (docs/phase-4-whatsapp.md). WhatsApp through WaSenderAPI — an unofficial API: if the number is
     * banned or disconnected, email (always on for money messages) keeps customers informed.
     */
    'notifications' => [
        // First line of every automated message, so a customer can tell who is writing from the notifications number.
        'sender_line' => 'ভবঘুরে হলিডেজ · Bhabaghure Holidays',
        'whatsapp' => [
            // live | fake (local stand-in, refused in production) | off
            'mode' => env('WASENDER_MODE', 'off'),
            'base_url' => env('WASENDER_BASE_URL', 'https://www.wasenderapi.com/api'),
            // The session API key only. Never the account-wide personal access token.
            'api_key' => env('WASENDER_API_KEY'),
            // WaSender sends this secret itself in X-Webhook-Signature.
            'webhook_secret' => env('WASENDER_WEBHOOK_SECRET'),
            // Account Protection allows one send per 5 seconds; a random pause is added on top.
            'seconds_between_sends' => (int) env('WASENDER_SECONDS_BETWEEN_SENDS', 5),
            'jitter_seconds' => (int) env('WASENDER_JITTER_SECONDS', 3),
            'daily_cap' => (int) env('WASENDER_DAILY_CAP', 500),
            'timeout_seconds' => 15,
            'max_attempts' => 12,
        ],
        'email' => [
            'enabled' => (bool) env('NOTIFICATIONS_EMAIL', true),
        ],
        // SMS through bulksmsbd.net (docs/phase-4-whatsapp.md §10): a fallback for money-critical messages WhatsApp
        // couldn't deliver, and the departure-day message. Never the review request, invoice PDFs or staff alerts.
        'sms' => [
            // live | fake (storage/logs/sms-fake.log; refused in production) | off
            'mode' => env('BULKSMSBD_MODE', 'off'),
            // Always HTTPS, always POST (the key goes in the form body, never in a URL). There is no automatic
            // downgrade to http: a TLS failure is retried and alerted, because a forced fallback would hand the key
            // to whoever broke the connection. docs/deployment.md §4a.
            'url' => env('BULKSMSBD_URL', 'https://bulksmsbd.net/api/smsapi'),
            'api_key' => env('BULKSMSBD_API_KEY'),
            // The operator-approved sender ID. Blank means SMS is disabled: operators silently drop unapproved IDs.
            'sender_id' => env('BULKSMSBD_SENDER_ID'),
            // Blank: each SMS in the booking's language. "bn": always Bangla — bulksmsbd.net refuses English from a masking
            // (brand-name) sender ID (response 1012), so set this if the approved sender ID is a masking one.
            'locale' => env('BULKSMSBD_LOCALE') ?: null,
            // The "type" sent with Bangla/unicode text: "text" as their docs show; "unicode" if Bangla arrives garbled.
            'unicode_type' => env('BULKSMSBD_UNICODE_TYPE') ?: 'text',
            // Estimate shown to staff and stored per message; confirm with the client's bulksmsbd price list.
            'cost_per_part' => (float) env('BULKSMSBD_COST_PER_PART', 0.35),
            // The editor warns above warn_parts; a message longer than max_parts isn't sent at all.
            'warn_parts' => 3,
            'max_parts' => (int) env('BULKSMSBD_MAX_PARTS', 6),
            'timeout_seconds' => 15,
        ],
        // Staff sending from a booking or customer record (POST /admin/notifications/whatsapp).
        'staff_send' => ['per_minute' => 10, 'per_day' => 100],
        // Dhaka times for scheduled messages.
        'schedule' => [
            'documents_pending' => ['days_before' => 7, 'at' => '10:00'],
            'pre_trip_reminder' => ['hours_before' => 48, 'departure_at' => '10:00'],
            'departure_today' => ['at' => '06:00'],
            'trip_completed' => ['days_after' => 2, 'at' => '11:00'],
        ],
    ],

    'media' => [
        // Public disk: package, blog, team and gallery images only. Passport scans and payment evidence
        // go to the private disk and never through this pipeline.
        'disk' => env('MEDIA_DISK', 'public'),
        // Designer requirement: reject anything over 5 MB (phone photos straight off a camera roll).
        'max_upload_kb' => 5120,
        'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'webp_quality' => 80,
        // Refuse decompression bombs: a small file that expands to an enormous bitmap.
        'max_pixels' => 60_000_000,
    ],

];
