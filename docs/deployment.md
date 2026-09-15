# Deployment notes

**Status (2026-09-14): live.** https://bhabaghure.com.bd serves the website from the live CMS; `admin.`, `customer.`
and `www.` answer; `wallet.` is 404 until Phase 7. Deployed with `deploy.sh` (§7.2). Third-party keys are blank and
sending is off (§2). **Not yet working: `api.bhabaghure.com.bd` has no DNS record**, so the admin can't sign in and
the website's forms, bookings and sign-in can't reach the API (pages render — they read the API over loopback).
Certificate: an interim Let's Encrypt certificate by HTTP-01 for the five hosts that have DNS records; the DNS-01
wildcard replaces it once the Cloudflare token exists (§7.5). DNS hosts and the shared-server rules are in
`_design/DEPLOYMENT.md`; the short version is: nothing outside `/var/www/Bhabagure` without asking first, a new nginx
file (never an edited one), reload never restart, a dedicated MySQL database and user, a project Redis index and prefix,
systemd units named `bhabaghure-*`.

---

## 1. What runs where

| Piece | How | State |
|---|---|---|
| API | nginx (`deploy/nginx/bhabaghure.conf`) → **our own PHP-FPM master** `bhabaghure-php.service`, root `api/public` (§7) | **live** 2026-09-14 (reachable from browsers once `api` has a DNS record) |
| Admin | static `admin/dist` | **live** 2026-09-14 |
| Website + portal | `bhabaghure-web.service`: Next.js on 127.0.0.1:3340, two build slots (§7) | **running** 2026-09-14 (slot `.next-a`) |
| Scheduler | `bhabaghure-scheduler.timer` → `php artisan schedule:run` every minute | **enabled and running** 2026-09-14 |
| Queue worker | `bhabaghure-queue.service` → `php artisan queue:work redis` (one worker, §5) | **running** 2026-09-14, preflight passed |
| Invoice PDFs | Chrome for Testing in `/var/www/Bhabagure/tools` | **installed** |
| MySQL | database `bhabaghure` (utf8mb4_unicode_ci), user `bhabaghure_user@127.0.0.1` with privileges on `bhabaghure.*` only; the password was generated on the server and exists only in `api/.env` | **created** 2026-09-14 |
| Redis | the box's shared Redis 7.0 (localhost, 16 databases): **database 12 = queue, 13 = cache**, prefix `bhabaghure_`, client Predis | **reserved** — 0 and 3 hold other sites' keys; the worker refuses anything else |

What the scheduler runs is listed in `docs/handover.md` §4, with the rest of day-to-day operation: every setting,
unit, rollback, backup and restore.

## 2. `api/.env` on the server

Never committed (the repository is public). Written on the server on 2026-09-14 with every internal secret generated
there (`APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`, `REVALIDATE_SECRET`, also in `web/.env.production.local`) and every
third-party key blank, sending off (`WASENDER_MODE=off`, `BULKSMSBD_MODE=off`, `MAIL_MAILER=log`, `SSLCOMMERZ_MODE=sandbox`).
**Back up `APP_KEY` outside the server** — it encrypts passport numbers.

The config is cached: after editing `api/.env`, run `/var/www/Bhabagure/deploy/deploy.sh --reload-config` (re-caches,
reloads `bhabaghure-php`, restarts the queue worker, prints the active modes).

Beyond the Laravel basics:

| Variable | Value |
|---|---|
| `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` | queue and cache on the shared Redis |
| `REDIS_CLIENT=predis`, `REDIS_DB=12`, `REDIS_CACHE_DB=13`, `REDIS_PREFIX=bhabaghure_`, `REDIS_QUEUE_RETRY_AFTER=180` | our reserved databases (§5) — the server has no phpredis extension, and installing one is a system package |
| `WEB_URL`, `WEB_REVALIDATE_URL`, `REVALIDATE_SECRET` | the website, for links in messages and CMS refreshes |
| `PORTAL_URL` (optional) | the customer portal: payments started there return there; portal invites link to it. Unset, it is `WEB_URL` with `customer.` in front — `https://customer.bhabaghure.com.bd` |
| `ADMIN_URL` (optional) | the admin app: staff invitation and password-reset links open there. Unset, it is `WEB_URL` with `admin.` in front — `https://admin.bhabaghure.com.bd` |
| `SSLCOMMERZ_MODE=live`, `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD` | the client's store (phase 3) |
| `PDF_CHROME_PATH` | `/var/www/Bhabagure/tools/chrome-for-testing/current/chrome-headless-shell-linux64/chrome-headless-shell` |
| `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.sendgrid.net`, `MAIL_PORT=587`, `MAIL_USERNAME=apikey`, `MAIL_PASSWORD` | SendGrid (§4) |
| `MAIL_FROM_ADDRESS=noreply@bhabaghure.com.bd` | the authenticated domain |
| `WASENDER_MODE=live`, `WASENDER_API_KEY`, `WASENDER_WEBHOOK_SECRET` | WhatsApp (§3) |
| `BULKSMSBD_MODE=live`, `BULKSMSBD_API_KEY`, `BULKSMSBD_SENDER_ID`, `BULKSMSBD_COST_PER_PART` (+ `BULKSMSBD_LOCALE=bn` for a masking sender ID) | SMS (§4a) |
| `NOTIFICATIONS_EMAIL=true` | keep on — it is the fallback |

`api/.env.example` lists every variable with a comment.

## 3. WhatsApp notifications (WaSenderAPI)

### 3.1 Setting it up

1. **A dedicated number**, not the main +8801743939300 — a SIM used normally on WhatsApp for a few weeks first
   (warmed up) so a new number sending booking messages doesn't look like spam.
2. In the WaSender dashboard: create a session for that number, scan the QR code with the phone, turn **Account
   Protection on** (one message per 5 seconds). Keep message logging off unless the client wants WaSender to store
   message text.
3. Copy the **session API key** into `WASENDER_API_KEY`. Never the account's personal access token — it controls every
   session and key. **Rotate the key that was exposed in the design bundle before go-live.**
4. Webhook: URL `https://api.bhabaghure.com.bd/api/v1/webhooks/wasender`; events `messages.update`, `message.sent`,
   `session.status`, `messages.received`; the secret is a long random string, the same in `WASENDER_WEBHOOK_SECRET`.
5. Admin → **Site settings → Contact → Notifications WhatsApp number**: enter the number. Until it is there, customer
   WhatsApp messages are held (emails still go) — a customer must be able to find the number on the website, invoice
   and booking page before trusting a message from it.
6. Admin → **Notifications**: "Check connection now" should say Connected. Each salesperson verifies their own number
   under **My profile**; an admin then picks the alert recipients. Send a template test to your own number.

### 3.2 Known risk: WaSenderAPI is unofficial

WaSenderAPI drives a normal WhatsApp account; it is not WhatsApp's official Business Platform. Its terms say it is
"not supported, endorsed, or affiliated with WhatsApp Inc.", that the customer accepts the risk of account bans, and
that WaSender cannot help unblock a banned account. **If WhatsApp bans the notifications number, it is gone overnight**,
and every automated WhatsApp message stops.

What limits the damage, already built:

- **Email is the always-on fallback.** Every message that matters to money — booking received, booking confirmed with
  the invoice PDF, payment received, documents pending, pre-trip reminder — and every sales alert also goes by email,
  independently of WhatsApp (`NOTIFICATIONS_EMAIL=true`). A ban degrades the service; it doesn't stop it. Only the
  departure-day greeting, the post-trip review request and staff one-off messages are WhatsApp-only.
- The sending code is behind one interface (`App\Services\Notifications\WhatsApp\WhatsAppGateway`); nothing else knows
  which provider is used.
- It is a separate number: a ban never takes the main business line with it.
- Low ban risk by design: transactional messages only (no broadcasts), one send per 5 s plus random pauses, a daily cap,
  STOP / বন্ধ opt-out honoured automatically, and every message starts with "ভবঘুরে হলিডেজ · Bhabaghure Holidays".
- A dropped or banned session emails the people who manage notifications within 5 minutes (once an hour while it lasts).

**Named fallback for the WhatsApp channel itself: the WhatsApp Business Platform (Meta Cloud API).** The official,
supported route for automated business messages, but it needs Meta business verification, pre-approved message
templates and is paid per message. Moving to it is one new `WhatsAppGateway` class plus template approval — no changes to bookings,
templates in the admin, or the log.

**If the number is banned — runbook:**

1. Set `WASENDER_MODE=off` and run `deploy/deploy.sh --reload-config`. WhatsApp messages are recorded as "not sent"; emails
   carry on. (Left on, messages retry for about an hour and then show as failed.)
2. Clear the notifications number in Site settings so the website and invoices stop publishing a dead number.
3. Decide: a new warmed number on WaSender (steps 1–6 above, same day) or the Meta Cloud API (days to weeks for
   verification and template approval).
4. Publish the new number in Site settings — the website, invoice footer, booking page and confirmation emails pick it
   up without a deploy.

The full runbook, including how to tell a dropped session from a ban, is `docs/handover.md` §9.

## 4. Email (SendGrid)

1. SendGrid → Settings → **Sender Authentication → Authenticate your domain** for `bhabaghure.com.bd`. It gives three
   CNAME records (a return-path host and two `_domainkey` hosts); add them at the DNS host and verify.
2. Add DMARC: `TXT _dmarc` → `v=DMARC1; p=none; rua=mailto:<address the client reads>` first, then `p=quarantine` once
   reports look clean.
3. An API key with **Mail Send** permission only → `MAIL_PASSWORD` (username is literally `apikey`).
4. Test: Admin → Notifications → pick an email template → "Send test to my email".

Volume is low: a booking sends a handful of emails over its life (received, confirmed, reminders, alerts to each sales
recipient). Check SendGrid's current plan limits against that when choosing the plan.

## 4a. SMS (bulksmsbd.net)

A fallback for money-critical messages WhatsApp can't deliver, and the departure-day message
([`phase-4-whatsapp.md` §10](phase-4-whatsapp.md)).

1. **The client's approved sender ID** → `BULKSMSBD_SENDER_ID`. Until it is set SMS stays disabled: operators silently
   drop messages from an unapproved sender ID. A masking (brand-name) ID only accepts Bangla at bulksmsbd — then also set
   `BULKSMSBD_LOCALE=bn`.
2. The API key (the user rotates the one that was shared) → `BULKSMSBD_API_KEY`; `BULKSMSBD_MODE=live`.
3. If IP whitelisting is on in the bulksmsbd panel, add the VPS's outbound IP.
4. First real sends (to a staff phone, from Admin → Notifications → an SMS template → "Send a test SMS to my phone"):
   one Bangla, one English. Check both arrive readable (if Bangla is garbled: `BULKSMSBD_UNICODE_TYPE=unicode`) and that
   the balance dropped by the parts the editor showed; set `BULKSMSBD_COST_PER_PART` to the account's real price.

**Transport — checked 2026-09-13, not a known risk.** bulksmsbd.net's documented URL is `http://` with the key in the
query string. Tested with a fake key: HTTPS works with a valid certificate (Let's Encrypt, TLS 1.3) and POST form fields
are parsed, so the client uses HTTPS + POST only and the key never appears in a URL. There is no automatic fallback to
http — their server doesn't redirect http to https, so a downgrade would expose the key; a TLS failure retries and
alerts instead. **If bulksmsbd ever stops serving HTTPS**, SMS stops (with an alert) rather than sending the key in
clear: at that point treat the key as low-trust, decide whether to accept plain http explicitly, and rotate the key on a
schedule. Also: their API echoes a wrong key back in its error text, so provider error messages are never stored or
logged — only response codes.

## 5. Queue worker

Messages are queued after the database commit and sent by **one** worker, so WhatsApp sends stay serial; retries are
stored on the message row (not in the queue), so a restart loses nothing.

**Installed 2026-09-13** (approved) from `deploy/systemd/bhabaghure-queue.service` into `/etc/systemd/system`, verified
with `systemd-analyze verify`, enabled. Until the API is deployed its start condition (`api/artisan` exists) is unmet,
so it shows `inactive (dead)`. **After the first deploy run `systemctl start bhabaghure-queue.service`** (a condition is
only checked when the unit starts); after every later deploy, `php artisan queue:restart`.

| Rule on the shared box | How the unit keeps it |
|---|---|
| Namespaced | `bhabaghure-queue.service`, `SyslogIdentifier=bhabaghure-queue`, runs as `www-data` in `/var/www/Bhabagure/api` |
| Our own Redis database | `ExecStartPre=… bhabaghure:queue-preflight --redis-db=12 --redis-cache-db=13 --job-timeout=120` refuses to start unless `api/.env` uses databases 12 and 13, prefix `bhabaghure_`, and both hold no other project's keys (it counts keys only; checked against this server's Redis: passes on 12/13, refuses 3 — "365 keys without the prefix") |
| A slow job never runs twice | the preflight requires `REDIS_QUEUE_RETRY_AFTER` (180 s) > `--timeout` (120 s) |
| Restarts can't thrash | `Restart=always` after `RestartSec=15`; `StartLimitBurst=5` in `StartLimitIntervalSec=600` — a crash loop stops after five tries and stays failed. Normal exits (hourly `--max-time`, `queue:restart`) are a few an hour |
| A stopped worker is noticed | the scheduler's `notifications:dispatch` emails notification managers when messages sit unclaimed for 15 minutes (once an hour) |
| A stuck job can't starve other sites | `--timeout=120` kills a long job and the worker restarts; `KillMode=control-group` takes Chrome children with it; `RuntimeMaxSec=2h` backstop |
| Memory | `MemoryHigh=450M`, `MemoryMax=600M` including invoice-PDF Chrome, `MemorySwapMax=0` (the box's swap is nearly full); PHP also exits between jobs past `--memory=192` |
| CPU, IO, threads | `CPUQuota=50%` (half of one of the two CPUs), `Nice=10`, `IOWeight=50`, `TasksMax=192`, `OOMScoreAdjust=500` (killed before other sites under memory pressure) |
| Hardening | `NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=full`, `ProtectHome`, `ProtectKernelTunables/Modules`, `ProtectControlGroups`, `RestrictSUIDSGID`, `LockPersonality`. Chrome's sandbox rendered the same PDF under `NoNewPrivileges` as `www-data` on this server; `RestrictNamespaces` and `MemoryDenyWriteExecute` are left off because Chrome's sandbox and V8/PHP JIT need them. `systemd-analyze security` scores it 7.5 (an unhardened service is 9.6) |

Without the worker nothing is sent: the admin shows the messages as waiting, and the stall alert above goes out.

## 6. Phase 4 go-live checklist

- [ ] Rotated WaSender key in `api/.env`; the old one revoked in the dashboard
- [ ] Dedicated number warmed up, session connected, Account Protection on
- [ ] Webhook URL, events and secret set; a test message shows ✓✓ in Admin → Notifications → Message log
- [ ] Notifications number entered in Site settings; visible on the website contact section, footer, booking page and
      invoice footer
- [ ] SendGrid domain authenticated, DMARC published, test email received and not in spam
- [x] `bhabaghure-queue.service` installed and enabled (2026-09-13)
- [ ] After the first deploy: `systemctl start bhabaghure-queue.service` → `active (running)`, "Queue preflight passed" in
      `journalctl -u bhabaghure-queue`; `bhabaghure-scheduler.timer` enabled; a confirmation email arrives with its invoice
      PDF (proves Chrome under the unit's hardening)
- [ ] SMS: approved sender ID from the client in `BULKSMSBD_SENDER_ID`, rotated key, `BULKSMSBD_MODE=live`; a Bangla and
      an English test SMS arrive readable and the balance drop matches the editor's part count
- [ ] Sales staff verified their numbers; alert recipients chosen
- [ ] One real booking on the live site: WhatsApp + email to the customer, alert to sales

## 7. Server layout and shipping a change

### 7.1 Layout

```
/var/www/Bhabagure/                 a git checkout of main — never edited by hand
├── api/            .env (root:www-data 0640)      Laravel; storage/ and bootstrap/cache/ owned by www-data
├── admin/          .env.production.local          dist/ served by nginx (built into dist-next/, then switched)
├── web/            .env.production.local          .next-a/ and .next-b/: the two website build slots
├── deploy/         deploy.sh, nginx/, php-fpm/, systemd/   (the copies that are installed)
├── tools/          Chrome for Testing (not in git)
├── .deploy/        deploy logs, lock, web-slot.env (which slot is live), nginx backups (not in git)
└── .cache/         npm and composer caches, so nothing is written to root's home (not in git)
```

| Host | Served by |
|---|---|
| `bhabaghure.com.bd`, `customer.` | nginx → Next.js 127.0.0.1:3340 (the app picks website or portal from the Host header) |
| `www.` | 301 → apex |
| `admin.` | nginx, static `admin/dist` |
| `api.` | nginx → `/run/bhabaghure-php/php-fpm.sock`; also on **127.0.0.1:3341** (loopback only) for the website's server-side content fetches (`API_INTERNAL_URL`), so they never leave the box |
| `wallet.` | 404 until Phase 7; then an IP allow-list and basic auth in front of its own sign-in |
| anything else | `000-catch-all.conf`: 444 on :80, TLS handshake refused on :443 |

All hosts are behind Cloudflare (**SSL mode Full (strict)**); every block sets the real visitor IP from
`CF-Connecting-IP` for Cloudflare's ranges only, because Laravel rate-limits sign-ins, forms and bookings per IP.

**Why our own PHP-FPM master.** The shared `php8.3-fpm` serves another site (travelagencyweb) from its single
five-worker pool. A reload of that master cuts off that site's requests in flight, and sharing the pool lets either
site starve the other. `bhabaghure-php.service` runs `php-fpm8.3` with `deploy/php-fpm/bhabaghure.conf`: six workers,
75 s request limit, `MemoryMax=800M` and `TasksMax=256` (in-request PDF renders with Chrome; 64 tasks killed every render until 2026-09-15), `CPUQuota=100%`, OPcache not watching files (new code lands at the deploy's
reload, not file by file during `git pull`). Deploys reload that master only; the shared one is never touched.

**Why two website slots.** `next build` rewrites its output directory, and Next bakes the directory name into the
build, so a finished build cannot be renamed into place. The deploy builds into the slot that is not live while the
site keeps serving, then points `.deploy/web-slot.env` at it and restarts `bhabaghure-web`. The previous slot stays on
disk until the next build, and a build that fails or doesn't answer never replaces the live one.

### 7.2 Shipping a change

Locally (Phases 5–7 are built and tested against the local database first):

```bash
npm run lint && npm test && npm run test:api       # plus the e2e suites for the screens you touched
git push origin main
ssh root@187.77.144.38 /var/www/Bhabagure/deploy/deploy.sh
```

What `deploy.sh` does on the server, in order, stopping at the first failure:

1. **Refuses** if a tracked file was edited on the server or `origin/main` doesn't fast-forward; takes a lock.
2. `git fetch` + fast-forward, then continues with the script it just pulled.
3. Pauses our queue worker and scheduler timer (they load PHP files as they go).
4. `composer install --no-dev --optimize-autoloader`; `npm ci` only when `package-lock.json` changed.
5. Builds the admin into `admin/dist-next` and the website into the idle slot — each build in a transient
   `bhabaghure-build-*` scope capped at 1.6 GB and 1.5 CPUs at nice 10. Nothing live has changed yet.
6. `migrate --force` (the API answers 503 with Retry-After only while migrations are pending), `db:seed --force`
   (create-only seeders: new permissions and templates, never an edit made in the admin), `optimize`.
7. Reloads **`bhabaghure-php`** (the pool file is tested first), restarts **`bhabaghure-queue`**, starts the timer.
8. Switches `admin/dist`, switches the website slot and restarts **`bhabaghure-web`**; if the new build doesn't answer
   within 90 s it goes back to the previous slot.
9. Tells the website to drop cached content (the build rendered it before migrations), then health checks.
10. Reports drift: when `deploy/nginx/bhabaghure.conf` or a `deploy/systemd/bhabaghure-*` unit differs from the
    installed copy, it prints the diff — **it never installs them itself**.

It never restarts a shared service and never reloads nginx. Config changes are separate, deliberate steps:

- `deploy.sh --install-nginx` — backs up the installed file, installs the new one, runs `nginx -t`; on failure it puts
  the previous state back and does not reload, so the shared nginx is never left holding a config it cannot load.
- `deploy.sh --install-units` — installs changed `bhabaghure-*` units, `daemon-reload`, restarts only those.
- `deploy.sh --check` — pending commits and drift, changes nothing.
- `deploy.sh --reload-config` — after editing `api/.env`: re-cache config, reload `bhabaghure-php`, restart the worker.

Logs: `/var/www/Bhabagure/.deploy/logs/`. Services: `journalctl -u bhabaghure-php -u bhabaghure-web -u bhabaghure-queue`.
Access logs: `/var/log/nginx/bhabaghure-{web,admin,api}.access.log`.

**Rolling back** is a commit, not a server edit: `git revert` locally, push, run `deploy.sh`. (For the website alone,
the previous slot is still built: set `.deploy/web-slot.env` back and `systemctl restart bhabaghure-web`.)

### 7.3 First sign-in

```bash
cd /var/www/Bhabagure/api && runuser -u www-data -- php artisan staff:super-admin owner@example.com --name="Owner"
```

prints a temporary password once; it must be changed at the first sign-in. `--reset` gives an existing account a new
temporary password and signs it out everywhere. Run it yourself over SSH, so the password never passes through
anyone else's terminal or logs.

### 7.4 Shared-server changes made for this deploy (2026-09-14, each approved)

| Change | Why | Undo |
|---|---|---|
| `/etc/nginx/sites-available/000-catch-all.conf` + `sites-enabled` link (copy of `deploy/nginx/000-catch-all.conf`) | nginx sent unmatched hosts to the first site it loaded (SaniTiles), so `bhabaghure.com.bd` showed another client's app. Now 444 on :80, TLS refused on :443. No existing file edited (none carried `default_server`). Before/after: sanitileserp.com and admin.sanitileserp.com 200 with the same page; worldjumperbd.com and travelagencyweb.com 200; app.sanitileserp.com 410 before and after. Only names with no nginx block of their own changed: ours and four orphaned certificate names whose HTTPS was already broken (api.primeskyint.com, api.showterraflight.com, nirman.digiwebdex.com, travelsaas.digiwebdex.com) | remove both, `nginx -t`, reload |
| `bhabaghure-php.service`, `bhabaghure-web.service` in `/etc/systemd/system` | §7.1 | `systemctl disable --now`, remove, `daemon-reload` |
| `bhabaghure-scheduler.timer` enabled | §1 | `systemctl disable --now bhabaghure-scheduler.timer` |
| `apt install python3-certbot-dns-cloudflare` (8 new packages, 0 upgraded) | DNS-01 wildcard certificate on a Cloudflare zone | `apt remove python3-certbot-dns-cloudflare` |
| Certificate `bhabaghure.com.bd` in `/etc/letsencrypt` (renewal hook `nginx -t -q && systemctl reload nginx`) | §7.5 | `certbot delete --cert-name bhabaghure.com.bd` (after removing the nginx file) |
| `/etc/nginx/sites-available/bhabaghure.conf` + `sites-enabled` link, via `deploy.sh --install-nginx` | §7.1 | remove both, `nginx -t`, reload |
| MySQL database `bhabaghure`, user `bhabaghure_user@127.0.0.1` | §1 | `DROP DATABASE` / `DROP USER` |

### 7.5 Certificate

**Now (interim, 2026-09-14):** `certbot certonly --nginx --cert-name bhabaghure.com.bd` for `bhabaghure.com.bd`, `www.`,
`admin.`, `customer.`, `wallet.` — the same HTTP-01 method the other certificates on this box renew with. It works
because Cloudflare passes plain-http requests through to the server; with no nginx block of ours on :80 certbot adds
its challenge to the catch-all with a `rewrite … break` ahead of `return 444`, and removes it afterwards (staging
dry run first). Expires 2026-12-13; renews automatically. It depends on Cloudflare's **Always Use HTTPS staying off**
and does not cover `api.` (no DNS record yet).

**Planned (as specified): the DNS-01 wildcard.** When `/root/.secrets/certbot/bhabaghure.com.bd.ini` (0600,
`dns_cloudflare_api_token = …`, token scoped to Zone → DNS → Edit on this zone) exists:

```bash
certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/certbot/bhabaghure.com.bd.ini \
  --dns-cloudflare-propagation-seconds 30 --cert-name bhabaghure.com.bd \
  -d bhabaghure.com.bd -d '*.bhabaghure.com.bd' --deploy-hook 'nginx -t -q && systemctl reload nginx'
```

Same certificate name, so the nginx file needs no change; renewal then no longer depends on plain http reaching the
server. Until then, when the `api` record exists: add `-d api.bhabaghure.com.bd` to the HTTP-01 command above
(with `--expand`).

**Certificate renewal baseline, before any change (2026-09-14 03:36 UTC):** `certbot renew --dry-run` — 38 of 42 pass.
The four failures are other sites' and pre-date this deploy: `api.primeskyint.com` (webroot challenge 404; also failed
2026-09-13), `app.sanitileserp.com` (410), `seventrip.net` (403) and `shanghaitravels.com.bd` (404) — the last three are
behind Cloudflare's proxy, which answers the HTTP challenge itself.

**After the catch-all, our nginx file and our certificate (2026-09-14 06:42 UTC):** 39 of 43 pass — the same 38 plus
`bhabaghure.com.bd`, with the same four failures. In that run `soft.smtradeint.com` also failed, but not at the
challenge: Let's Encrypt refused the request ("Unable to update challenge :: authorization must be pending", before any
HTTP check). That site has its own server blocks, so the catch-all never handles it. Re-run on its own at 06:52 it
passed.

### 7.6 Super admin wallet (Phase 7 step 5)

The wallet (docs/phase-7-hr-attendance-bonus-wallet.md §8) is served on `wallet.bhabaghure.com.bd` behind three doors:
the office IP allow-list, basic auth, then its own sign-in (the super admin's password and an authenticator code).
`deploy.sh` builds `wallet/dist` and runs the wallet migrations once the database exists. Four one-time steps, in
order, each run by a person on the server:

1. **Database and user** (approved 2026-09-15): `/var/www/Bhabagure/deploy/wallet-database.sh`. It creates
   `bhabaghure_wallet` and `bhabaghure_wallet@127.0.0.1` with rights on that database only, checks that the company user
   has no grant that reaches it, and writes `WALLET_DB_*`, `WALLET_KEY`, `WALLET_HOST` and `WALLET_COOKIE_SECURE` into
   `api/.env` without printing the password or key. Then `deploy.sh`.
2. **The allow-list.** Put one `allow <address>;` line per office public address in
   `/etc/nginx/bhabaghure-wallet/allow.conf` (0644, root). The addresses stay out of this public repository. Until the
   file exists every request gets 403.
3. **Basic auth.** Run it yourself, so the password never passes through anyone else's terminal:
   `printf 'owner:%s\n' "$(openssl passwd -apr1)" > /etc/nginx/bhabaghure-wallet/htpasswd`, then
   `chown root:www-data /etc/nginx/bhabaghure-wallet/htpasswd && chmod 0640 /etc/nginx/bhabaghure-wallet/htpasswd`.
4. **nginx:** `deploy.sh --install-nginx`. It runs `nginx -t` with the new files, then reloads nginx (never restarts it);
   the nginx file itself is already installed, so the reload is what makes the allow-list and basic auth take effect.
   Before 2026-09-15 this mode skipped the reload when the file was unchanged.

At the first wallet sign-in the page shows a QR code for an authenticator app; the first accepted code enrolls it. For a
lost phone: `cd /var/www/Bhabagure/api && sudo -u www-data php artisan wallet:reset-authenticator <email>`, then sign in
again to enroll a new one.

| Change | Why | Undo |
|---|---|---|
| MySQL database `bhabaghure_wallet`, user `bhabaghure_wallet@127.0.0.1` (wallet-database.sh) | wallet isolation enforced by MySQL | `DROP DATABASE bhabaghure_wallet; DROP USER 'bhabaghure_wallet'@'127.0.0.1';` and remove the `WALLET_*` lines |
| `/etc/nginx/bhabaghure-wallet/allow.conf` and `htpasswd` | the front door | remove the directory; the wallet then answers 403 to everyone |
