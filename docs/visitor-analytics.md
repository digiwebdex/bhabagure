# Visitor analytics (2026-10-24)

**Asked (the client):** see where the website's visitors come from — countries, regions and cities — how many there
are, which pages, tours and visas they look at, how they reach the site, on which devices, and how visits turn into
enquiries and bookings. Real collected data only; production-safe; privacy-respecting.

**Decided with the client (2026-10-24), each as recommended:**
1. **Visitors are counted without cookies**, by a salted code that changes every day. No consent banner is needed; new
   vs returning visitors can't be told, and visitors over many days are summed daily figures.
2. **Location comes from Cloudflare**, trusted only on requests that really came through it (an nginx rule). Country
   works at once; region and city once the owner switches on "Add visitor location headers" in Cloudflare.
3. **Visits are kept 13 months**, then deleted nightly (editable, 1–36 months).
4. **Super admin and Admin** see the reports and download CSVs (`analytics.view`, `analytics.export`); the settings
   are the super admin's alone (`analytics.manage`, held by no role).

## What exists (audit)

Stack: Laravel 13 + MySQL 8.4 API, Next.js website (App Router), React/Vite admin, one VPS behind Cloudflare. No
analytics or tracking existed before (no GA, Meta Pixel or similar; no cookie banner). nginx already restored the
visitor's IP from `CF-Connecting-IP` for Cloudflare's addresses only. Traffic on 23 October: about 1,450 page requests
and 350 IPs a day, roughly a quarter of them bots — about 45,000 page views a month.

## How it works

```
browser (website pages only)
  └─ POST api.…/api/v1/public/analytics/events   ← page views, steps, heartbeats (Cloudflare → nginx → Laravel)
       nginx: BH_FROM_CLOUDFLARE=1 only if the connection came from Cloudflare
       Laravel: bot? opted out? excluded path? → drop · location from CF-* headers if trusted · IP → daily code, then gone
       → analytics_sessions (one per tab visit) + analytics_events (page views, steps, conversions)
bookings and forms (API, on success) → conversion on the visit named by X-Analytics-Session
admin → GET admin/analytics/* (aggregates only)
nightly 03:40 → analytics:prune (retention)
```

**Website** (`web/src/lib/analytics.ts`, `features/VisitorAnalytics.tsx` in `SiteChrome`):
- A page view on each page, including in-app navigation and a package opened from its card (it counts as the package's
  page; closing it doesn't count the page underneath again). The same path within 2 seconds is one view (React Strict
  Mode, router echoes).
- `booking_started` when the booking form opens, with the package.
- A heartbeat every 30 seconds while the tab is visible (live visitors, time on site); when hidden or closed, the time
  since the last beat.
- The visit id: a random UUID in **sessionStorage** (this tab only), renewed after 30 idle minutes. Not a cookie.
- Sent in the background with `fetch(…, { keepalive })`, retried once; any failure is ignored — the website never waits
  for analytics.
- Nothing is sent when the browser asks not to be tracked (Global Privacy Control or Do Not Track).
- Only the landing page sends the referrer and the `utm_source / utm_medium / utm_campaign` tags. The path is sent
  without its query string.
- Bookings, enquiry forms and reviews carry `X-Analytics-Session` so the API can record their conversion.
- The customer portal (its own host) and the admin never load it.

**API:**
- `POST public/analytics/events` (`PublicAnalyticsController`): `{ session, events[≤10]{ key, type, path, subject? },
  heartbeat?, referrer?, utm?, locale? }`, at most 8 KB; types `page_view · package_view · booking_started` only.
  Always 202 once valid. Rate limit `analytics`: 60 a minute and 5,000 a day per address.
- `AnalyticsRecorder`: drops bots (`UserAgent`, user-agent markers — crawlers, link previews, headless browsers,
  scripts); normalises the path (`Paths`: `/en` prefix removed, `/booking/<ref>` → `/booking/:reference`, the
  unsubscribe token likewise, `/portal`, `/api`, `/admin` and Settings' excluded paths never counted); classifies the
  source (`Referrers`: campaign if utm_source, else search, social, another site, or direct — the referrer's domain
  only); locates (`VisitorLocation`); stores each event once by its key (unique index), a session once by its token.
  A session older than 12 hours takes no more events.
- Conversions (`AnalyticsRecorder::conversion`), recorded by the API after the action succeeded, once per key:
  `booking_completed` (booking saved), `enquiry` (contact form), `air_quote`, `hotel_quote`, `newsletter`, `review`,
  `payment_request`. Never from the browser. Not portal sign-ins: the portal is its own, untracked site.
- Reports (`AnalyticsReports`, `AnalyticsFilters`): `GET admin/analytics/summary`, `…/live`,
  `…/breakdown/{country|region|city|landing|exit|source|referrer|campaign|device|browser|os|language|page|tour|conversion}`
  (`page`, `per_page` ≤ 500), `…/export/{daily|<breakdown>}` (CSV, UTF-8 with BOM, ≤ 5,000 rows, formula-safe,
  audited `analytics.exported`), `GET/PUT …/settings` (audited `analytics.settings_updated`). Filters `from`, `to`
  (Dhaka days, ≤ 400), `country` (code or `unknown`), `device`, `source`, `path`. Every dimension is a fixed column.
- Settings: site setting `analytics` (`AnalyticsSettings`): enabled, retentionMonths 13, activeMinutes 5,
  sessionMinutes 30, excludedPaths.

**Location** (`VisitorLocation`): `CF-IPCountry` (XX and T1 = unknown), `CF-IPContinent`, and with Cloudflare's
"Add visitor location headers" (Rules → Settings → Managed Transforms) `CF-Region`, `CF-IPCity`, `CF-Timezone` —
read only when nginx passes `BH_FROM_CLOUDFLARE=1`, set from the connecting address (`geo $realip_remote_addr`), which
no request header can fake. Latitude, longitude and postcode are never read. Approximate: a VPN or a mobile network can
place someone elsewhere.

**Database** (migration `2026_10_24_100000_visitor_analytics.php`, new tables only):
- `analytics_sessions`: token (the tab's UUID, never shown), visitor_hash, started_at, last_seen_at, landing_path,
  last_path, locale, source, referrer_domain, utm_*, country_code, continent, region, city, timezone, device, browser,
  os, page_views, engaged_seconds.
- `analytics_events`: session_id (cascade), event_key (unique), type, path, subject (`package:<slug>`, `visa:<slug>`,
  `contact`), occurred_at.
- At today's traffic about 50,000 sessions and events a month: well within MySQL on this server. If it grew a hundred
  times, add a daily aggregate table and keep raw events shorter.

**Admin** (`admin/src/features/analytics`, Website → Visitor analytics): Overview (KPIs with the previous period, daily
chart, CSV), Locations (world map — Natural Earth outlines built once by `scripts/generate-world-map.cjs`, no map
service — plus countries, regions, cities), Live (sessions seen in the active window, refreshed every 15 seconds;
latest activity), Pages & tours, Traffic sources, Devices, Conversions, Settings (super admin). Period: today,
yesterday, 7/30/90 days, custom; filters by clicking a country, page, source or device; all in the address. Print
uses the browser.

## What the numbers mean

- **Visitors** — distinct daily visitor codes in the period: different browsers on each day. Back on three days =
  three. Two people on one network with the same browser on one day can count once.
- **Sessions** — one tab's visit, until 30 idle minutes; counted on the day it started.
- **Page views** — pages opened, the same path within 2 seconds counted once.
- **Average time on site** — seconds the tab was visible (heartbeats), not time open in the background.
- **One-page sessions** — sessions with one page view.
- **On the website now** — sessions seen within the active window (5 minutes).
- **Country share** — a country's sessions over all sessions in the period (Unknown included).
- **Conversions** — successful actions saved by the API (above); converting sessions have at least one.

## Privacy

No cookies; no IP address stored (it makes the day's code and is dropped; the day's salt is deleted after two days, so
codes can't be recomputed or linked across days); no names, phone numbers or form contents in analytics; referrers as
domains; booking references and tokens in paths replaced. Do Not Track and Global Privacy Control respected. Reports are
aggregates; Live shows place, pages and device only. Kept 13 months. The privacy policy (Website → Legal pages) has a
section on it.

## GA4

**Asked (2026-10-24):** an option to connect Google Analytics 4. **Decided, each as recommended:** the website sends
visits to GA4 (reports and Realtime are read in analytics.google.com); GA4 loads **only after the visitor accepts
cookies**; bookings and enquiries go to GA4 as events; only the **super admin** connects or disconnects it.

- **Connect:** Visitor analytics → Settings → Google Analytics 4 → paste the web stream's **Measurement ID** (`G-…`) →
  Save. The setup steps are on that screen. Emptying it disconnects. Saved in the `analytics` setting
  (`ga4MeasurementId`, checked against `^G-[A-Z0-9]{4,15}$`), served to the website in `GET public/settings` as `ga4`
  (a Measurement ID is public: it is in every page that uses GA4), and the website's settings are refreshed at once.
- **Website** (`lib/ga4.ts`, `features/CookieConsent.tsx`): with an ID, a banner (Accept / Decline) on the first visit;
  the answer is kept in this browser (`localStorage bh_cookie_choice`). Accept → Google's tag
  (`googletagmanager.com/gtag/js?id=…`) loads, `send_page_view: false`, and page views are sent by hand on the same
  "once per page" rule as our analytics. Decline → never loaded; declining after accepting removes the `_ga` cookies
  and reloads. Do Not Track / Global Privacy Control → never loaded, no banner. "Cookie settings" in the footer reopens
  the banner. Without an ID: no banner, no Google request at all.
- **GA4 events** (no names, phones, emails or form contents): `page_view`; `begin_checkout` (booking form opened, the
  package); `booking_made` (after the API saved it: `value` = the booking's total, `currency` BDT, `transaction_id` =
  booking reference, the package and travellers); `generate_lead` with `form` = enquiry · air_quote · hotel_quote (after
  the API accepted it); `newsletter_signup`. In GA4, mark `booking_made` and `generate_lead` as key events.
- GA4 counts only visitors who accept, so its figures are lower than our own (which count everyone, cookielessly).
  The two are not meant to match.

## Operating it

- **Install / update nginx:** `deploy.sh --install-nginx` (nginx -t first; the old file restored on failure).
- **City and region:** Cloudflare dashboard → the domain → Rules → Settings → Managed Transforms → "Add visitor location
  headers" → on. Nothing to change on the server.
- **Turn off:** Visitor analytics → Settings → "Record visits" off (nothing new recorded; reports stay).
- **Rollback:** switch off as above; to remove completely, revert the commit and `php artisan migrate:rollback --step=1`
  (drops the two tables). Nothing else depends on them.
- **Troubleshooting:** no countries → check `BH_FROM_CLOUDFLARE` reaches PHP (nginx installed?) and that requests go
  through Cloudflare. No visits → the website's `NEXT_PUBLIC_API_URL`, CORS (`X-Analytics-Session` allowed), the
  `analytics` rate limit, or Settings off. Failures are logged as `analytics: …` warnings and never shown to visitors.

## Tests

- API `VisitorAnalyticsTest`: location only via Cloudflare, no IP kept; duplicates, heartbeat cap, private paths, bots,
  no conversions from the browser; payload, size and rate limits; conversions once on the right visit; reports with
  filters, shares, campaigns, pages, tours, exits, bounded period, unknown reports; live and CSV; permissions and
  settings; retention.
- Website e2e `analytics.spec.ts`: one visit across pages, the package dialog, the booking form and an enquiry; opt-out.
- Admin e2e `analytics.spec.ts`: overview, map, country filter, pages, sources, conversions, CSV, live, settings.
