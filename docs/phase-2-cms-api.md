# Phase 2 — API, CMS and admin editors

**Status (2026-09-13): built and running on live data.** The Laravel API, the admin CMS editors and the website
on `CONTENT_SOURCE=api` are done. The endpoint list below was reviewed and approved (with the refinements in §6).

| Check | Result |
|---|---|
| API feature tests (MySQL 8.4) | 70 tests, 628 assertions |
| Admin end-to-end (admin → real API, `bhabaghure_e2e`) | 11 tests |
| Website end-to-end (production build on live API data) | 16 tests, incl. real forms, sign-up rules, the signed unsubscribe link, and a CMS save refreshing the site |
| Unit tests (format, pricing, website) | 29 tests |
| Lint and build | API (Pint), admin (ESLint, tsc, Vite), website (ESLint, Next build) |

---

## 1. Designer notes — how each one is enforced

| Note | Implementation | Test |
|---|---|---|
| Ledgers are append-only | **Application layer** (decided 2026-09-13; see §7): `Models\Concerns\AppendOnly` throws on `updating`/`deleting` and gives the model a query builder with no `update`, `delete`, `increment` or `upsert`; `Support\Database\LedgerQueryGuard` inspects every SQL statement on every connection before it runs and refuses `UPDATE`, `DELETE`, `TRUNCATE`, `REPLACE` and upserts against `transactions`, `bonus_transactions`, `audit_logs` and `wallet_transactions` (tables listed before they exist). No route updates or deletes a ledger. Corrections are reversing rows (`reverses_transaction_id`, unique). | `DatabaseIntegrityTest`, `LedgerImmutabilityTest` |
| Money `DECIMAL(12,2)`; `due_amount` generated | Every money column is `DECIMAL(12,2)`. `bookings.due_amount` and `invoices.balance_due` are `STORED` generated columns. `CHECK` constraints keep amounts ≥ 0 (`transactions.amount` > 0). None of this needs special MySQL privileges. | same |
| Wallet has no FK to the company schema | No wallet table exists in this app; the wallet gets its own database and MySQL user (Phase 7). | — |
| Invoices snapshot what was billed | `billed_*`, `package_*`, `pax_count`, `unit_price`, `discount_*`, `vat_*`, `total_amount` on `invoices` (`Invoice::SNAPSHOT_COLUMNS`). Once the invoice leaves draft, model events refuse changes to those columns, deleting the invoice, and adding, changing or removing its lines. Payment cache and void fields stay writable. | `DatabaseIntegrityTest` |
| Media: filename, mime, bytes, variants | `media.original_filename`, `mime`, `bytes`, `width`, `height`, `variants` (WebP thumb 400, card 800, detail 1600, full 2400 px wide, never enlarged). | `CmsMediaTest` |
| Draft / published on every CMS list | `status` on packages (`draft`/`published`/`archived`), posts, team, reviews, gallery. New items start as drafts; the public API returns published rows only (posts also need `published_at` ≤ now). | `PublicContentContractTest`, `CmsContentTest` |
| Uploads ≤ 5 MB, WebP variants | 5 MB limit (checked in the browser first, then by the API), JPEG/PNG/WebP only, a real image check, a 60-megapixel cap, EXIF orientation applied and EXIF (incl. GPS) removed. The raw upload is never stored. | `CmsMediaTest` |

**Optional database triggers.** The same ledger and invoice rules also exist as MySQL triggers
(`App\Support\Database\DatabaseGuardTriggers`), off by default behind `DB_GUARD_TRIGGERS`. They would also stop
clients outside this app (a DBA console). To switch them on once the host approves:
`DB_GUARD_TRIGGERS=true` then `php artisan db:guard-triggers install` (`status` / `drop` also available). Covered by
`DatabaseGuardTriggersTest`, which skips itself where MySQL won't allow trigger creation.

---

## 2. Conventions

- Everything is under `/api/v1`. `{id}` is a numeric id; public content is addressed by slug.
- Public content (`/public/*`) is camelCase with `{ bn, en }` pairs — the website's `ContentBundle` types. The staff
  API uses the database's snake_case field names (approved).
- Money is always a JSON number, never a formatted string.
- Errors: `401 unauthenticated`, `403 forbidden | password_change_required | account_suspended | invalid_link`,
  `404`, `409 has_bookings | media_in_use | category_in_use | contact_us`, `422` field errors (with readable field
  names in both languages) or `{code: "not_ready_to_publish", problems: [...]}`, `429`.
- Messages in Bangla unless `X-Locale: en` (or body `locale: "en"`). `Accept-Language` is ignored.
- Every CMS write records an audit row and, after the commit, refreshes the website's cache tag
  (`POST web /api/revalidate`, retried).

---

## 3. Authentication

| Method | Path | Notes |
|---|---|---|
| POST | `/staff/auth/login` | `{email, password}` → `{access_token, expires_in: 900, staff}` + refresh cookie. 5 failures/min per email + IP |
| POST | `/staff/auth/refresh` · `/staff/auth/logout` | Cookie only; replaying a rotated refresh token ends the session |
| GET | `/staff/auth/me` | |
| POST | `/staff/auth/change-password` | min 10 characters; signs out other sessions |
| POST | `/customer/auth/register` | `{name, phone, email?, password (8+), locale?}` → 201. A phone or email already on file → **409 `contact_us`**: "To open an account with this number, please contact us." (or "…this email address…"). The message never says the number belongs to a customer; the website shows it with a WhatsApp button. Checked only after the rest of the form is valid; 3 attempts/min and 20/day per IP |
| POST | `/customer/auth/login` | `{identifier, password}` — phone in any common form, or email |
| POST · GET | `/customer/auth/refresh`, `/logout` · `/me` | |

---

## 4. Public endpoints

**Read** (300/min per IP): `GET /public/destinations` · `/packages` · `/packages/{slug}` · `/departures` · `/posts` ·
`/posts/{slug}` · `/team` · `/reviews` · `/gallery` · `/pricing` · `/settings`.

**Forms** (5/min, 40/day per IP; filled `company` honeypot → 202, nothing stored): `POST /public/inquiries` ·
`/air-quotes` · `/newsletter`.

**Unsubscribe** (30/min per IP, separate from the forms limit):

| Method | Path | Notes |
|---|---|---|
| GET | `/public/newsletter/unsubscribe/{token}` | Masked address and status |
| POST | `/public/newsletter/unsubscribe/{token}` | Unsubscribes |

`{token}` is **signed**: `{subscriber id}-{HMAC-SHA256}` with a key derived from `APP_KEY`, over the id and the
subscriber's stored random token (`App\Support\NewsletterUnsubscribeToken`). It works without signing in, can't be
guessed or edited to point at another subscriber (403 `invalid_link`), and dies if the stored token is rotated. The
email link is `WEB_URL/newsletter/unsubscribe/{token}`: a website page that shows the address and an Unsubscribe
button (a POST, so mail scanners that open links unsubscribe nobody).

---

## 5. CMS endpoints (approved)

Permissions: Admin has all three; Tour operator has `packages.manage` and `pricing.manage`; Sales agent and
Accountant have none; Super admin passes every check.

| Area | Endpoints | Permission |
|---|---|---|
| Media | `GET/POST /admin/media`, `PATCH/DELETE /admin/media/{id}` | packages.manage or cms.manage |
| Packages | `GET/POST /admin/packages`, `PUT /admin/packages/order`, `GET/PUT/DELETE /admin/packages/{id}`, `POST …/{id}/publish · unpublish · archive` | packages.manage |
| Package photos | `POST /admin/packages/{id}/images`, `PUT …/images/order`, `DELETE …/images/{imageId}` | packages.manage |
| Departures | `GET/POST /admin/packages/{id}/departures`, `PUT/DELETE /admin/departures/{id}` | packages.manage |
| Destinations, tags | `GET/POST /admin/destinations`, `PUT /admin/destinations/{id}`, `GET /admin/tags` | packages.manage |
| Pricing | `GET/PUT /admin/pricing`, `GET/POST /admin/addons`, `PUT /admin/addons/{id}` | pricing.manage |
| Blog | `GET/POST /admin/posts`, `GET/PUT/DELETE /admin/posts/{id}`, `POST …/{id}/publish · unpublish`, `GET/POST /admin/blog-categories`, `PUT/DELETE …/{id}` | cms.manage |
| Team, reviews, gallery | `GET/POST /admin/{list}`, `PUT /admin/{list}/order`, `GET/PUT/DELETE …/{id}`, `POST …/{id}/publish · unpublish` | cms.manage |
| Site settings | `GET /admin/settings`, `PUT /admin/settings/{key}` | cms.manage |

**Publish checklist.** A package needs a Bangla title, **a price above zero, a duration of at least one day**, at
least one itinerary day, at least one inclusion and at least one photo. A post needs body and excerpt in both
languages. A gallery item needs a thumbnail. Saving a published package that would break the checklist is refused
and rolled back; so is removing its last photo.

---

## 6. Decisions and refinements (2026-09-13)

- **Approved:** the endpoint list, the publish checklist, the POST unsubscribe, blocking self-registration on known
  numbers until SMS verification, and the two JSON styles.
- **Customer passwords: 8 characters minimum** (API and website). The portal holds passport numbers.
- **Publish checklist** gained price and duration.
- **Unsubscribe links are signed** (§4) so they work without a login.
- **"Number already known" is neutral** (§3), with a WhatsApp button on the website. The same applies to a known
  email address. Note: any registration flow that refuses a known number still reveals that the number can't
  self-register; the wording avoids saying why, and the rate limit keeps it from being used to scan numbers.
- **Ledger immutability in the application layer**, triggers kept behind a flag (§1, §7).
- **Pricing:** the single-room supplement is separate from the group slab. A solo traveller sharing a room pays the
  list price; +12% applies only when single occupancy is chosen. `@bhabaghure/pricing` already worked this way; the
  shared fixtures now pin both cases, the admin Pricing screen presents the supplement as its own setting with a
  worked example from the same code, and the website FAQ reads the percentage from settings instead of hardcoding
  12%. (The `_design` copy on this machine is still dated 12 Sep 22:20 and shows the old rule — the re-synced
  version hasn't arrived locally.)

Other changes made while building:
- The unsubscribe token separator is `-`, not `.`: the website's router skips dotted paths (they look like files).
- Readable validation field names in both languages (e.g. "included item (English)" instead of `includes.0.text_en`).
- The website said "a verification link is on its way" after registration; no email is sent yet, so it now says the
  portal can be opened right away.

---

## 7. Admin editors

`admin/` — React 19, React Router, TanStack Query, TipTap. Signed-in staff see only the screens their permissions
allow; the API enforces the same rules. The access token lives in memory; a reload restores the session from the
httpOnly refresh cookie.

| Screen | What it does |
|---|---|
| Packages | Status and destination filters, search, ▲▼ card order, "No Bangla title" flags; destinations card |
| Package editor | Bangla and English side by side; itinerary days (add, reorder, remove); included / not-included lines; tags with suggestions; SEO with Google preview; live publish checklist; photos (upload or pick, reorder, cover, remove); group departures with seats booked; publish / unpublish / archive / delete; unsaved-changes guard |
| Pricing & add-ons | Group slab tiers; single-room supplement as its own card; service charge and max travellers; worked example computed with `@bhabaghure/pricing`; add-ons (retired, never deleted) |
| Blog | List with category and scheduled badges; editor with rich text limited to the server's allow-list, cover image, author, reading time, scheduling, SEO; categories |
| Team, reviews, gallery | Sortable lists with publish toggles and edit dialogs |
| Media library | Drag-and-drop upload with progress, 5 MB and type checks before sending, alt text and credit, delete when unused |
| Site settings | Contact, company, address, licence, hours, website stats — one save per card |

Numbers and dates go through `@bhabaghure/format`; number inputs accept Bengali digits. Each screen is its own
chunk, so the rich text editor loads only when a post is opened.

---

## 8. Local setup and deployment notes

- Docker Desktop is still not installed; the machine's MySQL 8.4 runs as a project-local server (`api/README.md`).
- End-to-end tests start their own API on port 8001 against `bhabaghure_e2e` (`scripts/e2e-api.mjs`) and never touch
  the dev database.
- **Deployment needs no server-wide MySQL setting**: the triggers are off by default.
- `WEB_URL`, `WEB_REVALIDATE_URL` and `REVALIDATE_SECRET` (same value in `web`) must be set on the server for
  unsubscribe links and cache refreshes.

## 9. Not built yet

1. Staff inbox for the inquiries and air quotes the forms store (Phase 5 leads screen).
2. Sending the newsletter and the unsubscribe email link (needs SendGrid).
3. SMS verification to let a known number claim its account.
4. PHP twins of `@bhabaghure/format` and `@bhabaghure/pricing` (Phase 3: invoices, WhatsApp text, booking totals).
