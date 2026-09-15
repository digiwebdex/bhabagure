# Bhabaghure v1.0 — handover

**For:** whoever runs Bhabaghure after the build, meaning the server admin, the next developer, and the client's IT
contact. It covers what runs, where its settings live, and what to do when something has to change or goes wrong.
§10 is for the client too: what v1.0 does not do.

**State on 2026-09-15:** everything below is deployed and live. Third-party accounts are still blank, so nothing is
sent and no real payment is taken yet (§1). The deeper design notes per phase are in `docs/phase-*.md`, and the
server's first set-up is in `docs/deployment.md`.

---

## 0. At a glance

| Host | What | Served by |
|---|---|---|
| `bhabaghure.com.bd` (and `www.` → 301) | Public website, Bangla first, English under `/en` | Next.js on 127.0.0.1:3340 (`bhabaghure-web`) |
| `customer.bhabaghure.com.bd` | Customer portal | the same Next.js server, chosen by host name |
| `admin.bhabaghure.com.bd` | Staff admin | static files, `admin/dist` |
| `api.bhabaghure.com.bd` | Laravel API for all of the above | PHP-FPM, its own master (`bhabaghure-php`); also on 127.0.0.1:3341, loopback only |
| `wallet.bhabaghure.com.bd` | Super admin wallet | static `wallet/dist` and the wallet API, behind the office IP allow-list and basic auth |

- **Server:** the shared VPS, `root@187.77.144.38`. Other clients' sites run on it, so follow the rules in §3.
- **Code:** `/var/www/Bhabagure`, a git checkout of `main` from the public repository `digiwebdex/bhabagure`.
  Nothing there is edited by hand; changes arrive through `deploy/deploy.sh`.
- **Data:** MySQL databases `bhabaghure` (the company) and `bhabaghure_wallet` (the wallet; its own user, no grant either
  way). Uploaded files are in `api/storage/app`. Redis databases 12 (queue) and 13 (cache) are reserved for us.
- **In front:** Cloudflare, with SSL mode *Full (strict)*. The Let's Encrypt certificate `bhabaghure.com.bd` renews
  automatically (§4).
- **At the office:** the attendance agent on one Windows PC reads the ZKTeco device and sends punches to the API
  (`agent/README.md`).

**Who holds what:**

| Secret | Where it lives | Keep a copy? |
|---|---|---|
| `APP_KEY` | `api/.env` | **Yes, offline.** It encrypts passport numbers, NID numbers, payout accounts and every uploaded passport scan, e-ticket and staff document. Lost = unreadable. |
| `WALLET_KEY` | `api/.env` | **Yes, offline, held by the super admin.** It encrypts the wallet's authenticator secret and evidence files. |
| `DB_PASSWORD`, `WALLET_DB_PASSWORD`, `JWT_SECRET`, `REVALIDATE_SECRET` | `api/.env` (`REVALIDATE_SECRET` also in `web/.env.production.local`) | No. They were generated on the server and can be regenerated. |
| SendGrid, SSLCommerz, WaSender, bulksmsbd, AWS keys | `api/.env`, once set | The providers' dashboards are the source; rotate there. |
| Wallet basic-auth password | `/etc/nginx/bhabaghure-wallet/htpasswd` | The super admin sets it themselves. |
| Backup decryption key | not on this server | The server admin holds it. |
| Cloudflare, domain registrar, SSH root | the client and the server admin | Change the root password that was shared during the build. |

## 1. Waiting before real customers

Built and deployed, but switched off until the account or decision exists:

- [x] **First staff account:** the super admin was created on 2026-09-15 with a temporary password, which must be changed
      at the first sign-in. More super admins, or a reset:
      `cd /var/www/Bhabagure/api && runuser -u www-data -- php artisan staff:super-admin <email> [--name="…"] [--reset]`
      (`docs/deployment.md` §7.3).
- [ ] **Customer portal sign-in** needs SMS or WhatsApp to be live: the portal signs in with a one-time code sent to
      the customer's phone. A customer already tried on 2026-09-14 at 18:37 UTC and got no code; the API logged it.
      **Once WhatsApp is live** (approved 2026-09-15), send that customer a one-line apology from the notifications
      number with the portal link, so they ask for a fresh code there. Use the admin's one-off WhatsApp message, which
      is logged. Don't send a code: codes last 10 minutes and work once.
- [ ] **Payments:** SSLCommerz live store ID and password → `SSLCOMMERZ_MODE=live` (§2.1).
- [ ] **Email:** SendGrid domain authentication, DMARC and an API key → `MAIL_MAILER=smtp` (`docs/deployment.md` §4).
- [ ] **WhatsApp:** a dedicated, warmed-up number on WaSender, its session key and webhook secret →
      `WASENDER_MODE=live`. Then publish the number in Site settings (`docs/deployment.md` §3.1).
- [ ] **SMS:** the operator-approved sender ID and the rotated API key → `BULKSMSBD_MODE=live` (`docs/deployment.md` §4a).
- [ ] **Passport OCR** (optional): an AWS IAM user limited to `textract:DetectDocumentText` →
      `PASSPORT_OCR_PROVIDER=textract`. Without it, customers type passport details by hand.
- [ ] **Wallet front door:** `/etc/nginx/bhabaghure-wallet/allow.conf` with the office's public address, and the
      `htpasswd` file; then `nginx -t && systemctl reload nginx` (`docs/deployment.md` §7.6). Until then the wallet
      answers 403 to everyone.
- [ ] **Attendance:** install the agent on the office PC and run `agent.exe test` against the device
      (`agent/README.md`).
- [x] **Uploaded files in the nightly backup:** added to the backup manifest on 2026-09-15, approved (§8.2).
- [ ] **Legal:** a lawyer's review of `/terms`, `/privacy` and `/refund-policy`; then set `BOOKING_TERMS_VERSION`.
- [ ] **Certificate (optional):** a Cloudflare API token for the DNS-01 wildcard certificate (`docs/deployment.md` §7.5).
      The current HTTP-01 certificate needs Cloudflare's *Always Use HTTPS* to stay off.
- [ ] **My commission's layout** against the re-synced design: the copy here is still the 2026-09-13 one. Automatic
      commission itself is built from the client's rules (§4, §10).

## 2. Settings: every environment variable

None of these files is in git. After changing one, apply it as the last column of this table says:

| File | Used by | Apply a change with |
|---|---|---|
| `api/.env` (root:www-data 0640) | API, queue worker, scheduler | `deploy/deploy.sh --reload-config` |
| `web/.env.production.local` (root:www-data 0640) | website and portal | `deploy/deploy.sh --force`: `NEXT_PUBLIC_*` values are built into the pages |
| `admin/.env.production.local` | admin | `deploy/deploy.sh --force`: built into the files |
| `.deploy/web-slot.env` | `bhabaghure-web` | written by `deploy.sh`; touch it only to roll the website back (§7) |
| office PC: `C:\ProgramData\Bhabaghure\Attendance\agent.ini`, `token.bin` | attendance agent | re-run `install.ps1` (`agent/README.md`) |

Before editing `api/.env`, keep the old copy: `cp -p api/.env .deploy/env-$(date +%F-%H%M)` (`.deploy` is root-only).

### 2.1 `api/.env`

"Secret" values are never written down anywhere but the file itself. "Unset" means the line is absent and the default
applies.

**Application**

| Variable | Production | What it does |
|---|---|---|
| `APP_NAME` | `Bhabaghure` | Name in emails and logs |
| `APP_ENV` | `production` | Must stay `production`: live payments refuse to run otherwise, and fake gateways refuse to run in it |
| `APP_KEY` | secret | Encryption key (see §0). **Never change it.** If it leaks, put the old value in `APP_PREVIOUS_KEYS` and a new one here, so existing data still decrypts |
| `APP_PREVIOUS_KEYS` | unset | Only when rotating `APP_KEY` |
| `APP_DEBUG` | `false` | `true` shows stack traces to visitors; never in production |
| `APP_URL` | `https://api.bhabaghure.com.bd` | The API's own address: links to invoices, short links, uploaded images |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `bn` / `en` | Default language of messages and PDFs |
| `APP_FAKER_LOCALE` | `en_US` | Development seeders only |
| `APP_MAINTENANCE_DRIVER` | `file` | `php artisan down` state, per server |
| `BCRYPT_ROUNDS` | `12` | Password hashing cost |

**Logs**

| Variable | Production | What it does |
|---|---|---|
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `daily` | One file a day: `api/storage/logs/laravel-YYYY-MM-DD.log` |
| `LOG_DAILY_DAYS` | `14` | Days of log files kept |
| `LOG_LEVEL` | `info` | `debug` only while chasing a problem |

**Company database**

| Variable | Production | What it does |
|---|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT` | `mysql`, `127.0.0.1`, `3306` | The shared MySQL 8 |
| `DB_DATABASE` / `DB_USERNAME` | `bhabaghure` / `bhabaghure_user` | Rights on `bhabaghure.*` only |
| `DB_PASSWORD` | secret | Generated on the server |
| `DB_GUARD_TRIGGERS` | `false` | Optional MySQL triggers for the append-only tables. They need a server-wide MySQL setting, so they stay off on the shared server; the application enforces the rules either way |

**Sign-in (staff and customers)**

| Variable | Production | What it does |
|---|---|---|
| `JWT_SECRET` | secret | Signs access tokens. Changing it signs everyone out, and nothing else |
| `JWT_ALGO` | `HS256` | Token algorithm |
| `JWT_TTL` | `15` | Access token minutes |
| `AUTH_REFRESH_TTL_DAYS` | `14` | Days a sign-in lasts without use |
| `AUTH_REFRESH_REUSE_GRACE_SECONDS` | `10` | A just-rotated refresh token still works for this long (reloads, two tabs) |
| `AUTH_REFRESH_COOKIE_SECURE` | `true` | HTTPS-only cookie |
| `AUTH_REFRESH_COOKIE_DOMAIN` | blank | Blank = the API host only; keep it so |

**The other apps**

| Variable | Production | What it does |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | `https://bhabaghure.com.bd,https://customer.bhabaghure.com.bd,https://admin.bhabaghure.com.bd` | Browsers allowed to call the API with credentials. The wallet is same-origin and isn't listed |
| `WEB_URL` | `https://bhabaghure.com.bd` | Website links in messages and emails |
| `PORTAL_URL` | unset → `https://customer.bhabaghure.com.bd` | Portal links; portal payments return there |
| `ADMIN_URL` | unset → `https://admin.bhabaghure.com.bd` | Staff invitation and password-reset links |
| `WEB_REVALIDATE_URL` | `http://127.0.0.1:3340/api/revalidate` | After a CMS save the API tells the website to refresh, over loopback |
| `REVALIDATE_SECRET` | secret | Must equal the one in `web/.env.production.local` |
| `CONTENT_SEED_PATH` | unset → `../packages/content-seed` | Seed content for a fresh database |

**Files, cache and queue**

| Variable | Production | What it does |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | Private files: `api/storage/app/private` |
| `MEDIA_DISK` | `public` | Website images: `api/storage/app/public`, served at `/storage` |
| `SESSION_DRIVER` | `array` | The API is stateless; sessions aren't used |
| `CACHE_STORE` / `CACHE_PREFIX` | `redis` / `bhabaghure_` | Cache, rate limits, scheduler locks |
| `QUEUE_CONNECTION` | `redis` | Messages and website refreshes go through the queue worker |
| `REDIS_CLIENT` | `predis` | The server has no phpredis extension |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | `127.0.0.1` / `6379` / blank | The shared Redis |
| `REDIS_DB` / `REDIS_CACHE_DB` / `REDIS_PREFIX` | `12` / `13` / `bhabaghure_` | **Reserved for us.** Databases 0 and 3 belong to other sites; the queue worker refuses to start on anything else |
| `REDIS_QUEUE_RETRY_AFTER` | `180` | Must stay above the worker's 120 s job timeout |

**Email**

| Variable | Production | What it does |
|---|---|---|
| `MAIL_MAILER` | `log` → `smtp` at go-live | `log` writes emails to the log instead of sending them |
| `MAIL_HOST` / `MAIL_PORT` | `smtp.sendgrid.net` / `587` | STARTTLS; leave `MAIL_SCHEME` unset |
| `MAIL_USERNAME` | `apikey` | Literally that word, for SendGrid |
| `MAIL_PASSWORD` | blank → secret | A SendGrid key with *Mail Send* permission only |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `noreply@bhabaghure.com.bd` / `Bhabaghure` | The authenticated domain |
| `NOTIFICATIONS_EMAIL` | `true` | Email copies of booking, payment and reminder messages. **Keep on:** it is the fallback when WhatsApp stops (§9) |

**Bookings and payments**

| Variable | Production | What it does |
|---|---|---|
| `SSLCOMMERZ_MODE` | `sandbox` → `live` | `live` works only with `APP_ENV=production`; `fake` is for tests and refuses production |
| `SSLCOMMERZ_STORE_ID` / `SSLCOMMERZ_STORE_PASSWORD` | blank → secret | The client's store |
| `SSLCOMMERZ_FALLBACK_EMAIL` | `info@bhabaghure.com.bd` | Sent to SSLCommerz when a customer has no email |
| `BOOKING_HOLD_MINUTES` | `45` | Seats held for an unpaid online booking |
| `BOOKING_TERMS_VERSION` | unset → `draft-2026-09` | Recorded on every booking as the terms the customer accepted. Set a new label when reviewed terms are published |

**Passport OCR and PDFs**

| Variable | Production | What it does |
|---|---|---|
| `PASSPORT_OCR_PROVIDER` | `none` | `textract` reads the passport's machine-readable zone |
| `AWS_TEXTRACT_REGION` / `AWS_TEXTRACT_KEY` / `AWS_TEXTRACT_SECRET` | `ap-south-1` / blank / blank | An IAM user limited to `textract:DetectDocumentText` |
| `PDF_NODE_BINARY` | `/usr/bin/node` | Runs the PDF renderer |
| `PDF_CHROME_PATH` | `/var/www/Bhabagure/tools/chrome-for-testing/current/chrome-headless-shell-linux64/chrome-headless-shell` | Chrome for invoices, payslips and quotations; not in git |
| `PDF_RENDERER` | unset → `packages/pdf/src/render.mjs` | The render script |

**WhatsApp (WaSenderAPI)**

| Variable | Production | What it does |
|---|---|---|
| `WASENDER_MODE` | `off` → `live` | `off` skips WhatsApp: emails continue, and money-critical messages fall back to SMS. `fake` is for tests |
| `WASENDER_API_KEY` | blank → secret | The **session** key of the notifications number, never the account's personal token |
| `WASENDER_WEBHOOK_SECRET` | blank → secret | A long random string, the same in the WaSender dashboard |
| `WASENDER_SECONDS_BETWEEN_SENDS` / `WASENDER_JITTER_SECONDS` | `5` / `3` | Pacing for Account Protection (one send per 5 s), plus a random pause |
| `WASENDER_DAILY_CAP` | `500` | Messages per Dhaka day; the rest wait until 09:00 Dhaka the next day |
| `WASENDER_BASE_URL` | unset → `https://www.wasenderapi.com/api` | Provider address |

**SMS (bulksmsbd.net)**

| Variable | Production | What it does |
|---|---|---|
| `BULKSMSBD_MODE` | `off` → `live` | SMS fallback and the departure-day message |
| `BULKSMSBD_URL` | `https://bulksmsbd.net/api/smsapi` | HTTPS and POST only; the key never goes in a URL |
| `BULKSMSBD_API_KEY` | blank → secret | Rotate the one shared during the build |
| `BULKSMSBD_SENDER_ID` | blank | The approved sender ID. Blank = SMS disabled |
| `BULKSMSBD_LOCALE` | blank | `bn` for a brand-name (masking) sender ID, which only accepts Bangla |
| `BULKSMSBD_UNICODE_TYPE` | `text` | `unicode` if Bangla arrives garbled |
| `BULKSMSBD_COST_PER_PART` / `BULKSMSBD_MAX_PARTS` | `0.35` / `6` | Cost estimates in the admin; set to the real price |
| `SHORT_LINK_BASE` | blank → `APP_URL/i/{code}` | Short invoice links in SMS |

**Wallet** (written by `deploy/wallet-database.sh`)

| Variable | Production | What it does |
|---|---|---|
| `WALLET_DB_HOST` / `WALLET_DB_PORT` | `127.0.0.1` / `3306` | |
| `WALLET_DB_DATABASE` / `WALLET_DB_USERNAME` | `bhabaghure_wallet` / `bhabaghure_wallet` | Its own user, with rights on its own database only |
| `WALLET_DB_PASSWORD` | secret | |
| `WALLET_KEY` | secret | See §0. Must differ from `APP_KEY`: the wallet refuses a key equal to it |
| `WALLET_HOST` | `wallet.bhabaghure.com.bd` | The only host the wallet API answers on |
| `WALLET_COOKIE_SECURE` | `true` | |
| `WALLET_IDLE_MINUTES` / `WALLET_ABSOLUTE_MINUTES` | unset → `30` / `720` | Wallet sign-out after idling, and at the latest |
| `WALLET_ISSUER` | unset → `Bhabaghure wallet` | The name shown in the authenticator app |

**Laravel's own, unused by Bhabaghure:** the framework reads further variables with safe defaults — `AWS_*` (S3),
`MEMCACHED_*`, `DYNAMODB_*`, `SQS_*`, `BEANSTALKD_*`, `POSTMARK_*`, `RESEND_API_KEY`, `SLACK_*`, `PAPERTRAIL_*`, `DB_URL`,
`DB_SOCKET`, `DB_CHARSET`, `DB_COLLATION`, `MYSQL_ATTR_SSL_CA`, the other `SESSION_*`, `MAIL_URL`, `MAIL_EHLO_DOMAIN`,
`AUTH_GUARD`, `JWT_*` beyond the three above. Leave them unset.

### 2.2 `web/.env.production.local`

| Variable | Production | What it does |
|---|---|---|
| `CONTENT_SOURCE` | `api` | Content comes from the live CMS (`seed` is for design work only) |
| `CONTENT_DEMO` | `0` | Seed mode's demo content; off |
| `API_URL` | `https://api.bhabaghure.com.bd` | The API, and the base of CMS image URLs |
| `API_INTERNAL_URL` | `http://127.0.0.1:3341` | Server-side content fetches over loopback, so they never leave the box |
| `NEXT_PUBLIC_API_URL` | `https://api.bhabaghure.com.bd` | What browsers call: forms, bookings, sign-in |
| `NEXT_PUBLIC_FORMS_MOCK` | `0` | Seed mode only |
| `NEXT_PUBLIC_SITE_URL` / `NEXT_PUBLIC_PORTAL_URL` | `https://bhabaghure.com.bd` / `https://customer.bhabaghure.com.bd` | Canonical links, sitemap, cross-links |
| `PORTAL_HOSTS` | `customer.bhabaghure.com.bd` | Hosts that get the portal instead of the website |
| `REVALIDATE_SECRET` | secret | Same as in `api/.env` |

Set by the `bhabaghure-web` unit, not the file: `NODE_ENV=production`, `NEXT_TELEMETRY_DISABLED=1`, `NODE_OPTIONS`
(heap cap), and `NEXT_DIST_DIR` from `.deploy/web-slot.env` (`.next-a` or `.next-b`).

### 2.3 `admin/.env.production.local`, and the wallet

| Variable | Production | What it does |
|---|---|---|
| `VITE_API_URL` | `https://api.bhabaghure.com.bd` | The API the admin calls |
| `VITE_SITE_URL` | `https://bhabaghure.com.bd` | "View on website" links |

The wallet has no settings file in production: it calls its API on its own origin. `WALLET_API_URL` exists only for
local development.

### 2.4 The office attendance agent

`C:\ProgramData\Bhabaghure\Attendance\agent.ini` holds the API address, the device's address, port (4370) and Comm
Key. `token.bin` holds the device token, encrypted for that PC with Windows DPAPI. A new token comes from the admin's
Attendance screen (rotate), and is entered with `agent.exe set-token`. Everything else is in `agent/README.md`.

## 3. Services (systemd)

Rules on this shared server: our units are all named `bhabaghure-*`. Shared services (nginx, MySQL, Redis, the shared
`php8.3-fpm`) are **reloaded, never restarted**, and nginx only after `nginx -t` passes. Nothing outside
`/var/www/Bhabagure` changes without the server admin's agreement.

| Unit | What it runs | As | Limits | If it stops |
|---|---|---|---|---|
| `bhabaghure-php.service` | PHP-FPM 8.3, its own master with 6 workers, socket `/run/bhabaghure-php/php-fpm.sock`, pool `deploy/php-fpm/bhabaghure.conf` | www-data | 600 MB, 1 CPU, 75 s per request | The API, admin and portal stop; the website shows cached pages. `systemctl reload bhabaghure-php` (tests the pool file first) |
| `bhabaghure-web.service` | Next.js on 127.0.0.1:3340: the website and portal, from the slot in `.deploy/web-slot.env` | www-data | 600 MB, 1 CPU | Both hosts give 502. `systemctl restart bhabaghure-web` is ours, so a restart is fine |
| `bhabaghure-queue.service` | `php artisan queue:work redis --queue=notifications,default`, one worker. Its preflight refuses Redis databases other than 12/13 | www-data | 600 MB, half a CPU, 120 s per job | Messages wait (nothing is lost); after 15 minutes the scheduler emails the notification managers. `systemctl restart bhabaghure-queue` |
| `bhabaghure-scheduler.service` + `.timer` | `php artisan schedule:run` every minute (§4) | www-data | nice 10 | Reminders, reconciliation and alerts stop. `systemctl start bhabaghure-scheduler.timer` |

- **Transient, during a deploy:** `bhabaghure-build-*` scopes (1.6 GB, 1.5 CPUs) for composer, npm and the builds.
- **Transient, during a test run:** `bhabaghure-testrun-install` and `bhabaghure-testrun` (§6.2).
- **Installed unit files** are copies of `deploy/systemd/*`. `deploy.sh` reports any difference, and
  `deploy.sh --install-units` installs the changed ones and restarts only those.

**Logs:**
- **Services:** `journalctl -u bhabaghure-php -u bhabaghure-web -u bhabaghure-queue -u bhabaghure-scheduler --since -1h`.
- **Application:** `api/storage/logs/laravel-*.log` (14 days).
- **PHP:** `api/storage/logs/php-errors.log` and `php-fpm-slow.log`. These two are not rotated, so check their size
  now and then.
- **nginx:** `/var/log/nginx/bhabaghure-{web,admin,api}.access.log`.
- **Deploys:** `.deploy/logs/`.

**Shared services we depend on:** `nginx` (`/etc/nginx/sites-available/bhabaghure.conf` and `000-catch-all.conf`),
`mysql`, `redis-server`, and the `php8.3` binaries.

## 4. Scheduled jobs

Bhabaghure has **no crontab entries**. `bhabaghure-scheduler.timer` runs Laravel's scheduler every minute, and it runs
these jobs (`api/routes/console.php`; Dhaka is UTC+6). Each runs on one server only, and the jobs marked
*no overlap* never overlap themselves.

| Command | When | What it does |
|---|---|---|
| `notifications:dispatch` | every minute, no overlap | Sends due messages (scheduled, paced, retried); alerts when the queue worker isn't taking them |
| `notifications:check-whatsapp` | every 5 min | Records the WhatsApp session status; alerts on a dropped or banned session |
| `attendance:watch-devices` | every 5 min | During duty hours on working days, alerts once when a device has had no good pull for an hour |
| `payments:reconcile` | every 10 min, no overlap | Settles or expires SSLCommerz payments that never called back |
| `quotations:remind-expiring` | hourly | Reminds customers in a sent quotation's last 24 hours |
| `passport-scans:prune` | hourly | Deletes passport scans no booking used |
| `bookings:complete-travelled` | 02:30 Dhaka | Confirmed bookings whose trip has ended become completed |
| `bookings:check-paid` | 03:15 Dhaka | Compares each booking's paid amount with the cash book; alerts on any difference |
| `staff-documents:remind-expiring` | 09:00 Dhaka | Alerts 30 days before a staff document expires, and on the day |
| `commission:volume-bonus` | 00:30 Dhaka on the 1st, no overlap | Posts last month's 0.5 % volume bonus to everyone with 10 or more confirmed bookings, once per person and month. For a missed month: `php artisan commission:volume-bonus 2026-09` |

To see them: `cd /var/www/Bhabagure/api && runuser -u www-data -- php artisan schedule:list`.

**Outside the project, relied on:**

| Job | Owner | When | Note |
|---|---|---|---|
| `certbot.timer` | system | twice a day | Renews certificate `bhabaghure.com.bd`; its hook runs `nginx -t -q && systemctl reload nginx` |
| `/opt/backup/backup.sh` (root's crontab) | server admin | 02:30 UTC (08:30 Dhaka) | Every database on the server, ours included (§8) |
| `logrotate.timer` | system | daily | nginx logs |
| *Bhabaghure Attendance Agent* (Windows Task Scheduler) | office PC | every minute, as SYSTEM | Checks in; pulls punches every 15 minutes |

## 5. Deploy

Every change goes through git. Build and test locally, push, then deploy:

```bash
npm run lint && npm test && npm run test:api        # plus the e2e suites for the screens you touched
git push origin main
ssh root@187.77.144.38 /var/www/Bhabagure/deploy/deploy.sh
bash deploy/smoke.sh                                 # from your machine, afterwards (§6.1)
```

`deploy.sh` stops at the first failure. In order, it:

1. Refuses if a tracked file was edited on the server, or if `main` doesn't fast-forward.
2. Pauses the queue worker and the scheduler.
3. Runs `composer install --no-dev`, and `npm ci` when the lockfile changed.
4. Builds the admin, the wallet and the website into directories nothing serves yet.
5. Runs migrations for both databases (the API answers 503 only while they run), then create-only seeders, then
   `permissions:sync --add-only`, then `optimize`.
6. Reloads `bhabaghure-php` and restarts the worker and the timer.
7. Switches the admin and wallet builds and the website slot. If the new website doesn't answer within 90 s, it goes
   back to the old slot.
8. Refreshes the website's content cache, then runs the health checks. Those include the wallet refusing this server
   (403) and the wallet API being absent on the API host (404).
9. Reports any drift between `deploy/nginx`, `deploy/systemd` and the installed copies.

Other modes:

| Command | When |
|---|---|
| `deploy.sh --check` | See pending commits and config drift; changes nothing |
| `deploy.sh --force` | Rebuild although the server is already on `main` (after editing `web/` or `admin/` env files) |
| `deploy.sh --reload-config` | After editing `api/.env`: re-cache, reload PHP-FPM, restart the worker, print the active modes |
| `deploy.sh --install-nginx` | After a change to `deploy/nginx/bhabaghure.conf`. It backs up the installed file, installs the new one and runs `nginx -t`; on failure it restores the old file and doesn't reload |
| `deploy.sh --install-units` | After a change to `deploy/systemd/*` |

A deploy takes a few minutes, mostly the builds, and longer when `npm ci` runs. The site keeps serving throughout.

## 6. Checking that it works

### 6.1 Smoke test (read-only, any time)

`bash deploy/smoke.sh` runs from any machine with bash, curl and node, and only sends GET requests and CORS
preflights. It checks:
- every host and its certificate;
- that the wallet stays closed, and is absent on the API host;
- the public API and the website's pages on live content;
- that admin and portal APIs refuse anonymous calls;
- CORS.

**2026-09-15, after the Thailand change: 55 of 55 passed.** Observations:
- no `Strict-Transport-Security` header, because Cloudflare's HSTS setting is off, which is a choice for the client;
- about 0.1–0.2 s to first byte through Cloudflare.

### 6.2 The full test suites, on the server

The suites rebuild their databases from scratch, so **they must never run against the production databases**.
`deploy/testrun.sh` runs all of them on the VPS against four throwaway databases. It uses two test-only MySQL users,
each limited to its own two databases. Everything runs as www-data in a capped unit whose filesystem is read-only
except `.testrun`, and which can reach localhost only, so nothing can send a real message or take a payment. Run it
as root:

```bash
/var/www/Bhabagure/deploy/testrun.sh setup      # clones the deployed commit, creates the test databases and users
/var/www/Bhabagure/deploy/testrun.sh install    # composer, npm, Playwright's Chromium (~10 min)
/var/www/Bhabagure/deploy/testrun.sh run        # lint, unit, types, API, admin e2e, website e2e, wallet e2e (1–2 h)
/var/www/Bhabagure/deploy/testrun.sh status     # results so far; logs in /var/www/Bhabagure/.testrun/logs
/var/www/Bhabagure/deploy/testrun.sh cleanup    # drops the databases and users, deletes .testrun
```

Clean up the same day. The nightly backup needs 5 GB free, and would otherwise copy the test databases.

### 6.3 Production data, read-only

Checked on 2026-09-15, all read-only (SELECT queries, service status and logs):
- both databases have no pending migrations;
- the journal is balanced, and no booking's paid amount differs from the cash book;
- no failed jobs;
- the application's error log has nothing since 2026-09-14:
  - that day's errors were the payment reconciler complaining that SSLCommerz isn't configured (fixed);
  - the day's last entry was the portal sign-in code that couldn't be sent (§1);
- all four units are active;
- Nepal gives the visa on arrival; Thailand needs it in advance.

`bookings:check-paid` repeats the ledger check every night.

## 7. Rolling back

**Code: a new commit, never a server edit.**

```bash
git revert <commit>            # locally; several commits: git revert <oldest>^..<newest>
git push origin main
ssh root@187.77.144.38 /var/www/Bhabagure/deploy/deploy.sh
```

- **Migrations only go forward.** Reverting a commit doesn't undo a migration that already ran. Write a new migration
  that puts the schema back.
  - `php artisan migrate:rollback` is for a migration whose `down()` is a clean schema undo, and only when no data
    depends on it yet.
  - Data migrations (the Thailand visa, say) have an empty `down()` on purpose. Undo those in the admin.
  - **Never** roll back anything touching the ledger, audit, attendance, payroll, bonus or wallet tables: they are
    append-only books, and corrections are reversing entries.
- **The website alone, immediately:** the previous build is still on disk until the next deploy. Point
  `.deploy/web-slot.env` at it and restart:
  `printf 'NEXT_DIST_DIR=.next-a\n' > /var/www/Bhabagure/.deploy/web-slot.env && systemctl restart bhabaghure-web`.
  Use whichever of `.next-a` and `.next-b` isn't live. Then revert properly, because the next deploy builds over the
  idle slot.
- **Admin and wallet:** no previous build is kept; revert and deploy.
- **A settings change:** restore the copy you kept (§2),
  `cp -p .deploy/env-<date> api/.env && deploy/deploy.sh --reload-config`.
- **nginx:** revert the commit, then `deploy.sh --install-nginx`. Earlier installed copies are in
  `.deploy/nginx-backup/`.
- **Stop the API while deciding** (the website keeps its cached pages):
  `cd /var/www/Bhabagure/api && runuser -u www-data -- php artisan down --retry=60`, and later `... php artisan up`.

## 8. Backups and restore

### 8.1 What is backed up

The server-wide nightly job `/opt/backup/backup.sh` belongs to the server admin, not this project. It runs at 02:30
UTC and finds every database on the server by itself:

| What | Where | Kept |
|---|---|---|
| `bhabaghure`: `mysqldump --single-transaction --routines`, gzip | `/var/backups/auto/daily/<date>/mysql-bhabaghure.sql.gz` (root-only) | 14 daily, 8 weekly (Sundays), 12 monthly (the 1st) |
| `bhabaghure_wallet`, the same way, from the first run after it was created (2026-09-16) | `…/mysql-bhabaghure_wallet.sql.gz` | same |
| Uploaded files, `api/storage/app`, from 2026-09-16: a manifest entry, `files \| bhabaghure-storage \| /var/www/Bhabagure/api/storage/app`, added 2026-09-15 (approved; the manifest's previous copy is `manifest.conf.before-bhabaghure-2026-09-15`) | `/var/backups/auto/uploads/<date>/bhabaghure-storage/`, hard-linked between nights | 14 days |
| An encrypted copy of each night's dumps (age), and of the file snapshots on Sundays | the server admin's Google Drive | about 90 days; the decryption key is **not** on this server |

To check last night's run: `grep bhabaghure /var/log/backup/backup.log | tail -3`, or `/opt/backup/restore.sh list`.

### 8.2 What is not backed up: act on this

- **The uploaded files, before 2026-09-16.** The backup job only finds directories named `uploads`. Our files (passport
  scans and photos, e-ticket PDFs, payment receipts, staff documents, wallet evidence, website images) were covered only
  once the manifest line above was added. Check the first run: `grep bhabaghure-storage /var/log/backup/backup.log`.
  - Passport scans, traveller documents, e-tickets and staff documents are encrypted with `APP_KEY`, and wallet
    evidence with `WALLET_KEY`, so restoring them needs those keys too. Payment receipts and website images aren't
    encrypted.
- **The settings files:** `api/.env`, `web/.env.production.local`, `admin/.env.production.local`.
  - Keep them in the client's password manager.
  - Without `APP_KEY`, restored passport numbers and encrypted files can't be read.
  - Without `WALLET_KEY`, the wallet's evidence can't be read. Its authenticator can simply be enrolled again.

### 8.3 Restoring

**Look before overwriting.** This restores into a separate database, readable by root only:

```bash
/opt/backup/restore.sh dates
/opt/backup/restore.sh mysql-bhabaghure 2026-09-20            # → database bhabaghure_restored
mysql bhabaghure_restored -e "SELECT reference, status, paid_amount FROM bookings ORDER BY id DESC LIMIT 10"
mysql -e "DROP DATABASE bhabaghure_restored"                  # when done
```

**Replacing the live company database** loses everything recorded since that night's dump: bookings, payments,
invoices, messages, attendance. Do it only when the live data is worse than that loss.

1. Tell staff to stop working, and note the time.
2. `systemctl stop bhabaghure-scheduler.timer bhabaghure-queue.service`
3. `cd /var/www/Bhabagure/api && runuser -u www-data -- php artisan down --retry=60`
4. Keep today's state, in case: `mysqldump --single-transaction --routines bhabaghure | gzip > /var/backups/auto/manual/bhabaghure-before-restore-$(date +%F-%H%M).sql.gz`
5. `/opt/backup/restore.sh mysql-bhabaghure <date> --overwrite` (it asks for confirmation)
6. `runuser -u www-data -- php artisan migrate --force && runuser -u www-data -- php artisan optimize`
7. `runuser -u www-data -- php artisan up`, then `systemctl start bhabaghure-queue.service bhabaghure-scheduler.timer`
8. `deploy/deploy.sh --force`, which also refreshes the website's cached content.
9. **Reconcile the lost window:**
   - online payments in the SSLCommerz panel;
   - cash, bank and mobile-wallet receipts against the statements;
   - invoice numbers, which will be issued again, so void and explain duplicates.
   Messages sent in that window aren't in the log, and scheduled ones may go out a second time. If that matters, set
   `WASENDER_MODE=off` and `BULKSMSBD_MODE=off` until the message log has been checked.

**The wallet database:** the same steps with `mysql-bhabaghure_wallet`, and with the wallet not in use.
It needs the same `WALLET_KEY`.

**From the off-site copy** (the server is lost or the local backups are gone):
1. `/opt/backup/restore.sh fetch mysql-bhabaghure <date>` downloads the encrypted file.
2. Decrypt it with `age -d -i <key file>` wherever the server admin keeps the key.
3. Load it with `gunzip -c mysql-bhabaghure.sql.gz | mysql bhabaghure`.

On a new server, first set the server up as `docs/deployment.md` §7, with the saved `.env` files. Then restore the
databases, then the files.

**Files**, once they're in the backup:
1. `/opt/backup/restore.sh files/bhabaghure-storage <date>` shows the snapshot's path.
2. `rsync -a <snapshot>/ /var/www/Bhabagure/api/storage/app/`
3. `chown -R www-data:www-data /var/www/Bhabagure/api/storage/app`

**Practise once a quarter:** restore into `bhabaghure_restored`, look at the latest bookings and payments, then drop it.

## 9. When WhatsApp bans the notifications number

WaSenderAPI drives an ordinary WhatsApp account. It is not WhatsApp's official business platform, so a ban can come
without warning, and WaSender can't reverse it (`docs/deployment.md` §3.2). The number is separate from the main
business line, which is unaffected.

**What you'll see:**
- The people who manage notifications get an email within 5 minutes saying the WhatsApp session isn't connected,
  repeated hourly while it lasts.
- Admin → Notifications shows the session as not connected, and the message log shows WhatsApp messages not sent.
- On the phone, WhatsApp says the number is banned.

**First, is it really a ban?** If the phone's WhatsApp still works, the session only dropped (phone off, logged out,
linked device removed). Re-scan the QR code in the WaSender dashboard and press *Check connection now*. You're done.

**If it is banned:**

1. **Stop sending.** In `api/.env` set `WASENDER_MODE=off`, then run `deploy/deploy.sh --reload-config`.
   - WhatsApp messages are recorded as skipped instead of retrying for an hour.
   - Emails carry on, with `NOTIFICATIONS_EMAIL=true`.
   - Once SMS is live, booking confirmed, payment received, the pre-trip reminder and documents pending go by SMS
     instead. The departure-day message goes by SMS as well.
   - The post-trip review request and staff one-off messages are WhatsApp-only; they are skipped.
   - Customers can still sign in to the portal with a code by SMS, once SMS is live.
2. **Stop publishing the number.** Admin → Site settings → Contact → clear *Notifications WhatsApp number*. The
   website, invoices and booking pages stop showing a dead number.
3. **Tell staff.** Their ✆ buttons open their own WhatsApp and keep working. For anything important in the message
   log since the ban, contact the customer by phone or their own WhatsApp.
4. **Revoke the old session** and its API key in the WaSender dashboard.
5. **Choose the replacement:**
   - **A new number on WaSender (same day)** if a warmed-up SIM is ready: one used normally on WhatsApp for a few
     weeks. Keep one ready for exactly this. Then follow `docs/deployment.md` §3.1, steps 2–6:
     - create a session and scan its QR code, with Account Protection on;
     - put the new session key in `WASENDER_API_KEY`;
     - set the webhook with a new secret in `WASENDER_WEBHOOK_SECRET`;
     - set `WASENDER_MODE=live` and run `deploy.sh --reload-config`;
     - press *Check connection now*, send a template test to a staff phone, and publish the new number in Site settings.
   - **WhatsApp's official Cloud API (days to weeks):**
     - it needs Meta business verification, pre-approved message templates, and payment per conversation;
     - it needs a developer to add one class behind the existing `WhatsAppGateway` interface;
     - nothing else changes: bookings, templates and the message log stay as they are.
6. **Optionally appeal** from the banned phone (WhatsApp → *Request a review*). Even if the number comes back, think
   twice before automating on it again.
7. **Afterwards,** look for the cause in the message log:
   - a spike in volume;
   - many messages to people who never booked;
   - staff sending broadcast-like one-offs;
   - customers replying STOP.
   Lower `WASENDER_DAILY_CAP` if volume was the trigger.

## 10. What v1.0 does not do

So nobody is surprised. None of these exists in v1.0 unless a line says otherwise.

**Named by the client:**
- **Custom-trip quotations.** Quotations are for tour packages only. A custom trip is quoted outside the system, or
  built as a draft package first.
- **Air-ticket quotations.** The website's air-ticket enquiries arrive in *Air ticketing* as a queue: claim, assign,
  WhatsApp or email the customer, *Mark as quoted*. The quote itself is prepared and sent outside the system. There is
  no PNR, fare, airline-commission or BSP management. (E-tickets *can* be recorded per traveller on a package booking,
  and the customer sees them in the portal.)
- **Loyalty.** No points, tiers or rewards. (The portal's post-trip NPS question is built.)
- **Referrals.** No referral codes or rewards.
- **Transfers between money accounts.** Cash banked, or bKash to bank, can't be recorded as a transfer. Recording it as
  a cash-out plus a cash-in would misstate income and expense, so don't. Until the accounting module exists, the
  accountant can square the two balances with a **balance adjustment** out of one account and one into the other,
  with the deposit slip as the receipt. This touches neither income nor expense.

**Also not in v1.0:**
- **Commission on air tickets and hotels.** Commission *is* automatic on tour bookings: 3 % of the sale before VAT when a
  booking is confirmed, reversed when it's cancelled, and moved when it's reassigned. A 0.5 % volume bonus is added at
  month end for 10 confirmed bookings. The air (1.5 %) and hotel (2 %) rates are configured, but v1.0 records no air or
  hotel sales for them to apply to. Admins and the super admin earn no commission on bookings they own.
- **Sales and services:** hotel reservations; a visa-file service tracker (appointments, visa-only customers); the
  B2B sub-agent portal (net rates, credit); corporate accounts with credit terms and statements.
- **Pricing:** early-bird, last-minute and seasonal price rules. Group-size discounts are built.
- **Refunds:** no refund or credit-note workflow.
  - An online payment is refunded in the SSLCommerz panel.
  - A staff-recorded payment is corrected with *Reverse* and a reason.
- **Accounting and reports:** the double-entry journal is kept, but it has no screens for the chart of accounts, trial
  balance, ageing or VAT/tax returns. There is no reports module and no CSV or Excel export. Supplier costs and profit
  per tour aren't recorded.
- **Operations:** no departure calendar, itinerary builder, manifest or rooming list, on-tour expenses, or departure
  checklist.
- **Messaging and marketing:**
  - no unified inbox: customers' WhatsApp, Facebook and SMS replies aren't collected in the admin;
  - no broadcasts or campaigns;
  - newsletter sign-ups are stored, but nothing sends a newsletter and the admin has no subscriber list;
  - Facebook leads without a phone number can't be entered.
- **Customers:** no offline mode or installable app for the website or portal, and no saved itineraries on the device.
  This is deliberate: a shared phone would keep passport-related data.
- **HR:**
  - company documents (trade licence, IATA, TIN) aren't in the vault, which holds staff documents only;
  - branches and more than one office aren't supported;
  - there is no data import.
- **System:** no backup or monitoring screen in the admin; both are server-level (§8). No bookings side panel: a row
  opens the booking's page.
- **Built but not switched on** (§1): live payments, email, WhatsApp, SMS, passport OCR, the wallet's front door, and
  the attendance agent at the office.
