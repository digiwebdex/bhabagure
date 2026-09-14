# Phase 1 — schema and repository layout

**Status (2026-09-13): approved, with the designer notes below, and migrated.** The migrations in
`api/database/migrations` are the source of truth from here; where they differ from this document, the
difference is listed in [`phase-2-cms-api.md`](phase-2-cms-api.md) §1 and §6.

Designer notes applied: ledgers append-only (application layer; optional triggers behind `DB_GUARD_TRIGGERS`); money `DECIMAL(12,2)` (not 14,2) with
`due_amount` a stored generated column; no wallet foreign keys in either direction; invoices keep an
immutable snapshot of what was billed; media stores filename, mime, bytes and WebP variants.

Sources: `_design/README.md`, `DEPLOYMENT.md`, `whatsapp-config.js`, `data/wp-trips.json`,
and the sample data inside the `.dc.html` prototypes (field names, statuses, reference formats).

---

## 1. Repository layout

```
bhabagure/
├── api/                          Laravel — REST API, queue jobs, PDFs        → api.bhabaghure.com.bd
│   ├── app/
│   │   ├── Enums/                BookingStatus, PaymentMethod, NotificationEvent (16 events), …
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/{Auth,Admin,Portal,Public}/
│   │   │   ├── Middleware/
│   │   │   ├── Requests/         validation; messages in bn + en
│   │   │   └── Resources/        JSON out — numbers stay numbers, never pre-formatted strings
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Services/             LedgerService, DocumentNumberService, …
│   │   └── Support/Numerals.php  PHP twin of the numeral formatter (PDFs, WhatsApp text)
│   ├── config/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   │       └── data/wp-trips.json   copy of the client's public WP data (safe to commit)
│   ├── lang/{bn,en}/
│   ├── routes/api.php            /api/v1/…
│   ├── tests/{Feature,Unit}/
│   └── .env.example
├── admin/                        React 19 + Vite + Tailwind v4 → dist/         → admin.bhabaghure.com.bd
│   └── src/
│       ├── App.tsx               shell (Phase 1 placeholder)
│       ├── i18n/                 react-i18next, bn default; {bn,en}.json
│       ├── lib/                  useFormat() (binds the numeral function), useTheme()
│       │                         later: api client, auth token handling
│       └── features/             later: one folder per screen group (sales, finance, …)
├── web/                          Next.js 16 App Router                       → apex + customer.
│   ├── src/proxy.ts              host + locale routing (Next 16 renamed middleware to proxy)
│   ├── src/lib/host-routing.ts   the pure routing rules, unit-tested
│   ├── src/app/[locale]/
│   │   ├── site/                 public website   (internal segment, never in a public URL)
│   │   └── portal/               customer portal  (internal segment, customer. host only)
│   ├── src/i18n/                 next-intl routing, navigation, request config
│   └── messages/{bn,en}.json
├── packages/
│   ├── tokens/theme.css          Tailwind @theme — every colour, font, size, spacing, radius, shadow
│   └── format/                   formatNumber() / formatBdt() — THE numeral function (TypeScript)
│       └── fixtures.json         shared cases; the same file tests api/app/Support/Numerals.php
├── docs/
├── docker-compose.yml            local MySQL + Redis only — never used on the VPS
├── package.json                  npm workspaces: packages/*, admin, web
└── .gitignore
```

Later phases add `wallet/` (its own SPA, auth guard and database — see open item 10) and
`deploy/` (the nginx file, `bhabaghure-*` systemd units, `deploy.sh`).

**Web URLs.** Bangla is unprefixed (`bhabaghure.com.bd/packages`), English is under `/en`
(`bhabaghure.com.bd/en/packages`), and `/bn/…` redirects to the unprefixed URL. The same rules
apply on `customer.`. There is no Accept-Language redirect, so every visitor lands in Bangla first.

**On "one numeral function":** the browser (admin, web) and the server (invoice PDFs, WhatsApp
text) both render numbers, and they run different languages. So there is exactly one
implementation per runtime, and both are tested against the same `fixtures.json`
(`75000, bn → "৳ ৭৫,০০০"`, `150000, en → "BDT 1,50,000"`, …) so they cannot drift.
Nothing else in either codebase is allowed to convert digits.

---

## 2. Conventions

| Topic | Rule |
|---|---|
| Engine / charset | InnoDB, `utf8mb4`, `utf8mb4_0900_ai_ci` (Bangla needs full UTF-8) |
| Primary keys | `id BIGINT UNSIGNED AUTO_INCREMENT`. Human references (`BH-2609-041`, `INV-0412`, `BH-EMP-001`) are separate unique columns |
| Money | `DECIMAL(14,2)`, exact. BDT only, so no currency column. Never `FLOAT`. One `Money` cast on the server |
| Numerals | Stored as numbers only. No pre-rendered digit strings anywhere in the database |
| Statuses | `VARCHAR(20)` holding English slugs (`confirmed`), backed by PHP enums, translated at render time. Not MySQL `ENUM` — adding a status must not need an `ALTER TABLE` on a live table. Never a Bangla string in a status column |
| Bilingual content | Public catalogue/CMS text gets paired `*_bn` / `*_en` columns. Staff-typed operational text (notes, ledger descriptions) is one column, stored as typed. Names, addresses and passport data are one column |
| Phones | Digits in international form, `8801XXXXXXXXX` — the form WaSenderAPI needs |
| Time | Timestamps stored UTC. Business rules ("06:00 on travel day", "48h before departure") evaluated in `Asia/Dhaka`. Travel dates, DOB and passport expiry are `DATE` |
| Deletion | Soft deletes on staff, customers, clients, packages, bookings. Financial records are never deleted: invoices are voided, transactions reversed |
| Passport data | `passport_number` encrypted at rest (Laravel `encrypted` cast, AES-256) plus an HMAC hash column for lookup and duplicate checks. Scans are encrypted files on a private disk, never under `public/`. **Losing `APP_KEY` makes passport numbers unrecoverable** — it must be backed up outside the server and outside git |
| Document numbers | Generated from `document_sequences` under a row lock, so two simultaneous bookings can never get the same number |

---

## 3. Tables

Every table has `created_at` / `updated_at` unless noted. `FK` = foreign key, `null` = nullable.

### 3.1 Access control

**`staff`** — admin users; authenticatable on the `staff` guard

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_code | varchar(20) unique | `BH-EMP-001` |
| name | varchar(120) | |
| email | varchar(190) unique | login |
| phone | varchar(15) null | |
| password | varchar(255) | bcrypt |
| status | varchar(20) | `invited` / `active` / `suspended` |
| must_change_password | bool, default true | "force password change on first login" |
| locale | char(2), default `bn` | |
| last_login_at | timestamp null | |
| last_login_ip | varchar(45) null | |
| deleted_at | timestamp null | |

**`roles`**, **`permissions`**, **`role_has_permissions`**, **`model_has_roles`**, **`model_has_permissions`**
— from `spatie/laravel-permission`, with added columns:

| Table | Added columns |
|---|---|
| roles | `name_bn`, `name_en`, `is_system` (seeded roles cannot be deleted) |
| permissions | `module` (bookings, finance, …), `name_bn`, `name_en` |

`name` holds a slug (`sales_agent`, `bookings.create`); `guard_name` = `staff`. A staff member has
exactly one role (enforced in the service layer). Roles and permissions are separate tables,
not a string column.

### 3.2 People and organisations

**`clients`** — organisations billed on account (corporate accounts and B2B agencies)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| type | varchar(20) | `corporate` / `b2b_agent` |
| name | varchar(160) | "Brac Bank Ltd", "Sylhet Travel House" |
| contact_name | varchar(120) null | |
| contact_phone | varchar(15) null | |
| contact_email | varchar(190) null | |
| city | varchar(80) null | |
| payment_terms | varchar(20) | `prepaid` / `net_15` / `net_30` / `net_45` |
| credit_limit | decimal(14,2), default 0 | |
| agent_tier | varchar(20) null | `silver` / `gold` / `platinum` — B2B only |
| travel_policy | text null | "Economy only · 2 trips/yr" |
| status | varchar(20) | `pending` / `active` / `paused` |
| deleted_at | timestamp null | |

Outstanding balance, credit used %, and year-to-date spend are **derived** from invoices and
transactions — never stored.

**`customers`** — individual people: leads and travellers; authenticatable on the `customer` guard

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(160) | full name as on passport |
| phone | varchar(15) | login identifier; unique among non-deleted rows |
| email | varchar(190) null | unique among non-deleted rows |
| password | varchar(255) null | null = created by staff, no portal login yet |
| stage | varchar(20) | `lead` / `customer` |
| source | varchar(20) | `facebook` / `whatsapp` / `phone_call` / `website_form` / `referral` / `walk_in` |
| address | varchar(500) null | shown on invoices |
| client_id | FK clients null | employee of a corporate, or an agent's customer |
| assigned_staff_id | FK staff null | owning sales agent — drives "sees own bookings only" |
| locale | char(2), default `bn` | |
| phone_verified_at | timestamp null | |
| email_verified_at | timestamp null | |
| last_login_at | timestamp null | |
| notes | text null | |
| deleted_at | timestamp null | |

Passport details are **not** on this table. They belong to a trip (`booking_travellers`), because
a passport can be renewed between trips and the traveller is not always the account holder.

### 3.3 Catalogue

**`destinations`**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| slug | varchar(80) unique | |
| name_bn / name_en | varchar(80) | নেপাল / Nepal |
| country_code | char(2) null | null for domestic regions |
| region | varchar(20) | `international` / `domestic` |
| sort_order | smallint | |

**`tour_packages`**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| code | varchar(30) unique | `Nepal 01` |
| wp_trip_id | int unsigned null unique | provenance of the WordPress import |
| slug | varchar(190) unique | |
| destination_id | FK destinations | |
| title_en | varchar(255) | verbatim from WP |
| title_bn | varchar(255) null | the client has not supplied Bangla titles yet |
| summary_en / summary_bn | varchar(500) null | inclusions line on the card |
| duration_days | tinyint unsigned | |
| duration_nights | tinyint unsigned null | |
| regular_price | decimal(14,2) | per person, base slab (WP `price`) |
| sale_price | decimal(14,2) null | WP `salePrice`; the card shows `regular_price` as the was-price |
| difficulty | varchar(20) null | |
| image_path | varchar(255) null | CMS-managed upload |
| source_image_url | varchar(500) null | WP image URL, kept only for the one-time photo import |
| status | varchar(20) | `draft` / `published` / `archived` |
| is_featured | bool | |
| sort_order | smallint | |
| created_by_staff_id | FK staff null | |
| deleted_at | timestamp null | |

WP's `discountLabel` ("6% Off") is not stored. It is derived from the two prices; storing it is
how it would drift. All three WP labels match the derivation (80,000 → 75,000 = 6%;
30,000 → 27,000 = 10%; 26,000 → 22,500 = 13%).

**`package_itinerary_days`** — `id`, `tour_package_id` FK (cascade), `day_number` tinyint,
`title_en` varchar(255) null, `title_bn` null, `body_en` text, `body_bn` text null.
Unique (`tour_package_id`, `day_number`).

**`package_inclusions`** — `id`, `tour_package_id` FK (cascade), `kind` (`include` / `exclude`),
`text_en` varchar(500), `text_bn` null, `sort_order`.

**`tags`** — `id`, `type` (`activity` / `trip_type`), `slug`, `name_en`, `name_bn` null.
Unique (`type`, `slug`). **`package_tag`** — pivot (`tour_package_id`, `tag_id`).

**`package_departures`** — scheduled group departures

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tour_package_id | FK | |
| departs_on / returns_on | date | |
| seats_total | smallint unsigned | seats left = total − pax on confirmed bookings (derived) |
| status | varchar(20) | `scheduled` / `closed` / `departed` / `cancelled` |
| group_leader_staff_id | FK staff null | |
| notes | text null | |

Not in your list, but bookings need it: seats-left bars, `low_seat_alert` and manifests all hang
off a departure. Nothing is seeded — WP only has placeholder 2021 dates.

### 3.4 Sales

**`bookings`**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| reference | varchar(20) unique | `BH-2609-041` |
| customer_id | FK customers | lead booker |
| client_id | FK clients null | when billed to a corporate or agent |
| tour_package_id | FK null | null for custom trips ("Kashmir", "Maldives + Sri Lanka dual") |
| departure_id | FK package_departures null | null for "any date" packages |
| package_title_en / package_title_bn | varchar(255) | snapshot at booking time |
| travel_start / travel_end | date null | |
| pax_count | smallint unsigned | |
| unit_price | decimal(14,2) | per person after group slab — snapshot |
| subtotal_amount | decimal(14,2) | |
| discount_amount | decimal(14,2), default 0 | |
| vat_amount | decimal(14,2), default 0 | |
| total_amount | decimal(14,2) | |
| paid_amount | decimal(14,2), default 0 | cached — see rule below |
| payment_status | varchar(20) | `unpaid` / `partial` / `paid` / `refunded` — cached |
| status | varchar(20) | `inquiry` / `confirmed` / `completed` / `cancelled` |
| source | varchar(20) | same values as `customers.source`, plus `b2b_agent` / `corporate` |
| assigned_staff_id | FK staff null | |
| created_by_staff_id | FK staff null | null = self-booked on the website |
| confirmed_at / completed_at / cancelled_at | timestamp null | |
| cancellation_reason | varchar(500) null | |
| internal_notes | text null | |
| deleted_at | timestamp null | |

Indexes: (`status`, `travel_start`), (`assigned_staff_id`, `status`), (`payment_status`),
`customer_id`, `departure_id`.

**Money integrity rule.** `paid_amount` and `payment_status` (here and on `invoices`) are written
only by `LedgerService`, in the same database transaction that inserts the `transactions` row,
with the booking row locked (`SELECT … FOR UPDATE`). A scheduled check compares them against
`SUM(transactions)` and alerts on any mismatch. Every screen reads money from the booking. There
is no second source.

Line-level detail (single supplement, add-ons) arrives with the Phase 3 booking flow.

**`booking_travellers`**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| booking_id | FK (cascade) | |
| customer_id | FK customers null | when the traveller has their own account |
| is_lead | bool | |
| full_name | varchar(160) | as on passport |
| date_of_birth | date null | |
| nationality | char(2), default `BD` | |
| passport_number | text null | encrypted |
| passport_number_hash | char(64) null, indexed | HMAC-SHA256 for search |
| passport_expiry | date null | drives the six-month-validity warnings |
| passport_scan_path | varchar(255) null | encrypted file on the private disk |
| ocr_filled_at | timestamp null | |
| phone | varchar(15) null | |
| email | varchar(190) null | |
| emergency_contact_name | varchar(120) null | |
| emergency_contact_phone | varchar(15) null | |
| sort_order | tinyint | |

The per-traveller document checklist (passport / visa / flight / hotel / emergency ticks, and the
per-country visa lists) becomes `traveller_documents` in the portal and visa phases.

### 3.5 Finance

**`invoices`**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| invoice_number | varchar(20) unique | `INV-0412` |
| booking_id | FK null | null leaves room for standalone air/hotel invoices |
| customer_id | FK customers | |
| client_id | FK clients null | |
| issued_on | date | |
| due_on | date null | |
| subtotal_amount / discount_amount | decimal(14,2) | |
| vat_rate | decimal(5,2) | 0 / 2 / 5 / 7.5 / 15 — the invoice's VAT select |
| vat_amount / total_amount | decimal(14,2) | |
| paid_amount | decimal(14,2) | cached, same rule as bookings |
| balance_due | decimal(14,2) **generated** | `total_amount − paid_amount`, stored |
| status | varchar(20) | `draft` / `issued` / `void` |
| payment_status | varchar(20) | `unpaid` / `partial` / `paid` — the PAID / PARTIAL / UNPAID pill |
| share_token | char(40) unique | `customer.bhabaghure.com.bd/invoice/{token}`; random, rotatable |
| pdf_path | varchar(255) null | private disk |
| issued_by_staff_id | FK staff null | |
| voided_at | timestamp null | |
| void_reason | varchar(500) null | |

No soft delete. An issued invoice is a frozen snapshot; corrections are a void and reissue (or a
credit note, in the refunds phase). The company-header on/off toggle is a print option, not a
column.

**`invoice_items`** — `id`, `invoice_id` FK (cascade), `title_en`, `title_bn` null, `detail`
varchar(255) null ("DAC–KTM–DAC"), `note` varchar(255) null ("Biman BG 371/372"),
`quantity` decimal(10,2), `unit_price` decimal(14,2) (0 renders as "included"),
`line_total` decimal(14,2), `sort_order`.

**`transactions`** — the company cash book: money that actually moved

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| direction | varchar(3) | `in` / `out` |
| amount | decimal(14,2) | `CHECK (amount > 0)`; `direction` carries the sign |
| category | varchar(30) | `customer_payment` / `refund` / `supplier_payment` / `expense` / `salary` / `bonus_payout` / `other` |
| method | varchar(20) | `cash` / `bank` / `bkash` / `nagad` / `sslcommerz` / `shurjopay` / `cheque` |
| external_ref | varchar(100) null | gateway `tran_id`, bKash TrxID. Unique (`method`, `external_ref`), so a repeated gateway callback cannot double-credit |
| booking_id / invoice_id / customer_id / client_id | FK null | |
| description | varchar(500) | |
| reference_label | varchar(120) null | the "saved reference" preset text |
| evidence_path | varchar(255) null | private disk |
| occurred_at | datetime | |
| recorded_by_staff_id | FK staff null | null = gateway or system |
| reverses_transaction_id | FK transactions null | corrections are reversing entries |
| created_at | timestamp | **append-only** — no `updated_at`, no updates, no deletes |

The Accounting screen (chart of accounts, journal, ageing, VAT) comes later as `accounts` +
`journal_entries` / `journal_lines`, posted from this table. Deals (total → advance → due) come
with the payments screen.

**No wallet table references, or is referenced by, anything here.**

### 3.6 Notifications

**`notifications`** — outbound message log: one row per event × recipient × channel

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| event | varchar(40) | one of the 16 keys in `whatsapp-config.js` |
| channel | varchar(10) | `whatsapp` / `email` / `sms` |
| recipient_type / recipient_id | morph null | customer or staff; null for team numbers |
| to_address | varchar(190) | `8801…` or an email address |
| related_type / related_id | morph null | the booking, invoice or quote it concerns |
| locale | char(2) | |
| title | varchar(255) null | email subject |
| body | text | the message exactly as sent |
| attachment_path | varchar(255) null | e.g. the invoice PDF for `invoice_sent` |
| status | varchar(20) | `pending` / `sending` / `sent` / `delivered` / `failed` / `cancelled` |
| provider | varchar(20) | `wasender` / `sendgrid` / SMS provider |
| provider_message_id | varchar(100) null | |
| attempts | tinyint | |
| last_error | text null | |
| scheduled_for | datetime | timed events ("06:00 on travel day") |
| sent_at / delivered_at / failed_at | timestamp null | |
| dedupe_key | varchar(190) unique | e.g. `pre_trip_reminder:booking:41:whatsapp` — a scheduler re-run cannot double-send |

Indexes: (`status`, `scheduled_for`), (`related_type`, `related_id`).
This replaces Laravel's built-in database-notification table of the same name, which we don't use.
Editable bn/en message templates become `notification_templates` in Phase 4.

### 3.7 Support tables

**`document_sequences`** — `key` varchar(30) PK (`booking`, `invoice`, `employee`, …), `prefix`,
`next_value` int unsigned, `updated_at`. The design's sample references run on across months
(`BH-2608-036` → `BH-2609-037`), so the counter is global and `YYMM` is the creation month.

**`audit_logs`** — `id`, `actor_type` / `actor_id` (morph, null for the system), `action`
varchar(60), `auditable_type` / `auditable_id` (morph null), `changes` json null, `ip`
varchar(45), `user_agent` varchar(255), `created_at`. Append-only. Phase 1 writes logins, failed
logins, and role/permission changes. The admin's "immutable" audit log reads from here.

**Framework tables:** `failed_jobs`, `job_batches`, `password_reset_tokens`. The queue, cache and
JWT blacklist live in Redis, under a project-specific database index and key prefix.

---

## 4. Relationship map

```
destinations        1 ─ *  tour_packages
tour_packages       1 ─ *  package_itinerary_days, package_inclusions, package_departures
tour_packages       * ─ *  tags                          (via package_tag)

clients             1 ─ *  customers                     (client_id, nullable)
customers           1 ─ *  bookings                      (lead booker)
clients             1 ─ *  bookings                      (billed on account, nullable)
tour_packages       1 ─ *  bookings                      (nullable — custom trips)
package_departures  1 ─ *  bookings                      (nullable — "any date" packages)
bookings            1 ─ *  booking_travellers
customers           1 ─ *  booking_travellers            (nullable — traveller with own account)

bookings            1 ─ *  invoices
invoices            1 ─ *  invoice_items
bookings, invoices, customers, clients  1 ─ *  transactions   (all nullable)
transactions        1 ─ 1  transactions                  (reverses_transaction_id)

roles               * ─ *  permissions                   (spatie)
staff               * ─ 1  roles                         (exactly one)
staff               1 ─ *  customers, bookings           (assigned_staff_id)
notifications       → any record (related_*), any customer or staff member (recipient_*)
```

---

## 5. Roles and permissions

Roles are the System administration list (decision 8.1). The permission matrix below is my
derivation from the Roles & audit matrix (8 rows) plus the staff-visibility toggles in System
administration — review it. After seeding, the database is authoritative, and the admin Roles screen edits it.

| Permission | Admin | Sales agent | Accountant | Tour operator | Design source |
|---|:-:|:-:|:-:|:-:|---|
| bookings.view_all | ✓ | | ✓ | ✓ | |
| bookings.view_own | | ✓ | | | "Sees own bookings only" |
| bookings.create, bookings.update | ✓ | ✓ | | ✓ | matrix row 1 |
| bookings.delete | ✓ | | | | |
| customers.view | ✓ | ✓ | ✓ | ✓ | sales agents: own customers |
| customers.manage | ✓ | ✓ | | | |
| clients.manage | ✓ | | | | |
| b2b_rates.manage | ✓ | ✓ | | | matrix row 7 |
| packages.manage, pricing.manage | ✓ | | | ✓ | matrix row 4 |
| payments.view, invoices.manage, transactions.create_manual | ✓ | | ✓ | | matrix rows 2–3 |
| ledger.view_company_balance | ✓ | | ✓ | | Roles tab, "Company balance" column |
| staff.manage, bonus.manage | ✓ | | | | matrix row 5 |
| commission.view_own | | ✓ | | ✓ | "Can view own commission" |
| commission.view_all | ✓ | | ✓ | | |
| cms.manage | ✓ | | | | matrix row 6 |
| reports.view, reports.export | ✓ | ✓ | ✓ | ✓ | matrix row 8 |
| reports.profit_loss | ✓ | | ✓ | | "Can view profit & loss" |
| system.audit_view | ✓ | | | | |
| system.roles_manage | | | | | super admin only |

**Super admin** passes every check through `Gate::before`. It never depends on the matrix, so a
permission edit cannot lock the proprietor out.

---

## 6. Authentication

- **Libraries:** `php-open-source-saver/jwt-auth` (the maintained fork of tymon/jwt-auth) and
  `spatie/laravel-permission`. Their current releases require Laravel 12 or 13 — see 8.2.
- **Two guards:** `staff` (admin) and `customer` (portal). Each token carries a claim naming its
  user model, so a customer token is rejected on admin routes and vice versa.
- **Tokens:** a short-lived access token (15 min) kept in memory by the SPA, plus a refresh token
  in an `httpOnly; Secure; SameSite=Strict` cookie scoped to the API host. The refresh token is
  rotated on use; logout and rotation blacklist the old token in Redis.
- **Passwords:** bcrypt via Laravel `Hash`. Staff minimum 10 characters. Customers 6+, as in the
  design copy "পাসওয়ার্ড (৬+ অক্ষর)" — I'd raise this to 8 (see 8.6).
- **Brute force:** 5 attempts per minute per identifier + IP; failures go to `audit_logs`.
- **Phase 1 endpoints:** staff login / refresh / logout / me / change-password; customer
  register / login / refresh / logout / me. OTP sign-in and password reset come with the website
  and portal phases.
- **API docs:** OpenAPI generated from the code (Scramble), so the docs cannot drift from the
  routes, served with Swagger UI on the API host.

---

## 7. Seeding `wp-trips.json`

- The file is copied to `api/database/seeders/data/`. It is the client's public WordPress API
  output — company contact details that are already public, no customer data.
- **6 packages.** `code`, `slug`, `wp_trip_id`, `title_en` verbatim, destination,
  days / nights, `price` → `regular_price`, `salePrice` → `sale_price`.
- `activities` and `tripTypes` become `tags`; the counts in the taxonomy list ("Boating (7)") are
  stripped.
- Itinerary lines are split into `day_number`, title and body. `DAY 01: DHAKA ➔ KATHMANDU — Arrival…`
  becomes day 1, title "DHAKA ➔ KATHMANDU", body "Arrival…". A line without " — " is body only.
- `includes` / `excludes` → `package_inclusions`. `image` → `source_image_url`.
- **Thai 02** is seeded as `draft`: WP gave no id, no nights, no itinerary and no inclusions
  (its own note says to re-fetch).
- Destinations: the 5 from the WP taxonomy, with the Bangla names the website design already
  uses (নেপাল, থাইল্যান্ড, মালদ্বীপ, শ্রীলঙ্কা).
- The seeder is idempotent (`updateOrCreate` by `code`), so re-running it is safe. It never
  truncates, which matters on the shared MySQL instance.
- Local dev staff accounts are seeded only when `APP_ENV=local`. Passwords are random and printed
  once, never committed.

---

## 8. Open items

### Decided (2026-09-13)

1. **Roles — the System administration list:** Super admin, Admin, Sales agent, Accountant,
   Tour operator. The Roles & audit matrix's HR column has no role of its own; staff and bonus
   management sits with Admin. "Content / social" and "Office assistant" become custom roles
   later if needed.
2. **Stack — current supported versions**, a deliberate divergence from the client's written
   stack: **Laravel 13** (PHP 8.3+), **Next.js 16**, **React 19** in both apps, **Tailwind v4**.
   Reason: Laravel 11 stopped receiving security fixes in March 2026, and the current jwt-auth and
   laravel-permission releases require Laravel 12+. The VPS must have PHP 8.3+ — not yet verified.
3. **`clients` = billed organisations** (corporate accounts and B2B agencies). `customers` = the
   individual people who enquire and travel.
4. **Local environment — Docker + Composer**, installed by the developer. *(Composer is installed; Docker
   is not yet, so a project-local MySQL 8.4 runs from `.devdb/` on the same port — see `api/README.md`.)* MySQL and Redis run from
   `docker-compose.yml`. The machine's native MySQL 8.4 on port 3306 is left untouched, so the
   container's MySQL listens on host port 3307.

### Not blocking Phase 1 — needed later

5. **Resolved (2026-09-13): the website's five tiers.** ~~Group-slab pricing conflict (Phase 2).~~ The website's `slabRate()` gives 1–2 pax base,
   3 pax −3%, 4–5 −6%, 6–9 −9%, 10+ −12%. The admin's pricing rules say 4+ −4%, 10+ −8%.
   Both can't be right, and "prices must match the detail modal's slab exactly".
6. **Resolved (2026-09-13): 8 characters.** ~~Customer password minimum.~~ The design says 6+ characters; 8 is the safer floor.
7. **Bangla package titles.** WP data is English-only. `title_bn` stays empty until the client
   supplies them; the admin will flag packages missing Bangla.
8. **Resolved (2026-09-13): 7 days / 48 hours / 06:00 / 2 days after return.** ~~Notification timings disagree (Phase 4).~~ `whatsapp-config.js` sends `documents_pending`
   48h before departure; the admin template says 7 days. `trip_completed` is 1 day after return
   in one, 2 days in the other. `pre_trip_reminder` is "24–48h" vs "48 hours".
9. **`/api/notifications/whatsapp` must require staff auth (Phase 4).** The prototype calls it
   with no credentials. Left open, it lets anyone send WhatsApp messages from the company number.
10. **Wallet isolation (Phase 7).** My recommendation is a separate database
    (`bhabaghure_wallet`) with its own MySQL user. The company API's database user then has no
    grant on it at all — isolation enforced by MySQL, not by code discipline. This needs a second
    database on the shared instance, so it's your call when we get there.
11. **Invoice compliance (Phase 3).** If the company is VAT-registered, NBR's Mushak-6.3 format may
    dictate invoice fields and numbering. Worth asking their accountant before the invoice is built.
12. **VPS facts to collect before deploy** (read-only): `php -v`, `mysql --version`,
    `redis-cli INFO keyspace` (to pick a free Redis index), `node -v`, and `ss -ltnp`.
    If the database server turns out to be MariaDB rather than MySQL 8, the collation and the
    `CHECK` / generated-column choices above need revisiting.
13. **Repository `.gitignore` — fixed.** `.env.*` also matched `.env.example`, so example files
    could never be committed; there is now a `!.env.example` exception (root and `web/`). Ignoring
    `storage/` wholesale also drops the empty directories Laravel needs at boot, so `deploy.sh`
    will recreate them.
14. **README's "admin dark theme" colours are the wallet's.** `#16203A / #0E1526 / #24304A /
    #8FA3C8` appear only in `Super Admin Wallet.dc.html`. The admin prototype's own dark theme is
    `#0B1220 / #121B2E / #182338 / #223050 / #9AA8C2`, and that is what `theme.css` uses, because
    the wallet must never look like the admin. The wallet values are kept as `wallet-*` tokens.
    Confirm with the designer.
15. **English URLs under `/en` (Phase 2, before launch).** Chosen so English pages can be indexed
    separately; the WordPress site being replaced was English. The alternative is a cookie-only
    toggle with no URL change (closer to the prototype), which would leave only the Bangla pages
    in search results. Hard to change once Google has indexed the site.
16. **Scroll-reveal distance.** The README says reveals fade up 20px; the website prototype's
    keyframe travels 26px. `theme.css` follows the README.
