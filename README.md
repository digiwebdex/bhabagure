# Bhabaghure Holidays — ERP, website and customer portal

Travel agency system for Bhabaghure Holidays Aviation, Agargaon, Dhaka.

| Folder | What | Host |
|---|---|---|
| `api/` | Laravel 13 REST API: auth, website content, CMS, bookings, SSLCommerz, invoices, WhatsApp and email notifications — see [`api/README.md`](api/README.md) | `api.bhabaghure.com.bd` |
| `admin/` | Staff app — bookings, invoices and payments, notifications, and the website CMS (React 19, Vite, Tailwind v4) — see [`admin/README.md`](admin/README.md) | `admin.bhabaghure.com.bd` |
| `web/` | Public website and customer portal — Next.js 16 | `bhabaghure.com.bd`, `customer.bhabaghure.com.bd` |
| `packages/tokens` | Every design token, as one Tailwind theme file | — |
| `packages/format` | The one function that turns numbers and dates into Bangla or English text | — |
| `packages/pricing` | Group-slab and booking price calculation, shared by every screen | — |
| `packages/pdf` | Invoice PDFs with headless Chrome, and the inspector the tests measure print output with | — |
| `packages/content-seed` | Bilingual seed content (packages, blog, team, pricing, settings) | — |

Plans and decisions: [`docs/phase-1-schema.md`](docs/phase-1-schema.md) (schema, auth) ·
[`docs/phase-2-website.md`](docs/phase-2-website.md) (website, build notes) ·
[`docs/phase-2-cms-api.md`](docs/phase-2-cms-api.md) (API and CMS endpoints, schema rules) ·
[`docs/phase-3-booking.md`](docs/phase-3-booking.md) (booking, SSLCommerz, invoices) ·
[`docs/phase-4-whatsapp.md`](docs/phase-4-whatsapp.md) (WhatsApp, email and SMS notifications) ·
[`docs/deployment.md`](docs/deployment.md) (server, environment, WhatsApp known risk and fallback, SMS, go-live checklist) ·
[`docs/phase-5-admin-core.md`](docs/phase-5-admin-core.md) (admin core — plan).

> **This repository is public.** Never commit credentials, API keys, `.env` files, or customer
> data (passport scans, payment evidence, invoices). If a secret is ever pushed, rotate it —
> deleting the commit is not enough.

## Run it locally

Prerequisites: Node.js 20.9+, PHP 8.3+ and Composer 2, MySQL 8.4 (Docker Desktop, or the project-local
server described in [`api/README.md`](api/README.md)).

```bash
npm install

cp .env.example .env              # then set DB_PASSWORD and DB_ROOT_PASSWORD
docker compose up -d              # MySQL on 127.0.0.1:3307, Redis on 127.0.0.1:6379 (or see api/README.md)

cd api && composer install && cp .env.example .env   # set DB_PASSWORD, then:
php artisan key:generate && php artisan jwt:secret && php artisan storage:link
php artisan migrate --seed && php artisan serve       # http://localhost:8000
cd ..

cp web/.env.example web/.env.local
# CONTENT_SOURCE=api reads the local API (set REVALIDATE_SECRET to the same value in api/.env and web/.env.local);
# CONTENT_SOURCE=seed works on the design without the API. CONTENT_DEMO=1 adds demo departures, reviews and
# gallery tiles; NEXT_PUBLIC_FORMS_MOCK=1 makes forms and sign-in succeed locally. Never on a server.

npm run dev:web                   # http://localhost:3000             website, Bangla
                                  # http://localhost:3000/en          website, English
                                  # http://customer.localhost:3000    customer portal
cp admin/.env.example admin/.env.local
npm run dev:admin                 # http://localhost:5173             admin (login: php artisan db:seed --class=DevStaffSeeder)
```

```bash
npm test                          # unit tests: formatter, pricing, filters, validators, routing
npm run test:api                  # API feature tests (MySQL bhabaghure_testing database)
npm run lint
npm run build
npm run test:e2e --workspace web    # website on live data: production build + a separate API on :8001 (bhabaghure_e2e)
npm run test:e2e --workspace admin  # admin against the same kind of separate API
```

## Rules every change follows

- **Bangla first.** Every user-visible string exists in `bn` and `en`; `bn` is the default.
- **Numbers go through `@bhabaghure/format` only** — `formatBdt(75000, 'bn')` → `৳ ৭৫,০০০`,
  `formatBdt(150000, 'en')` → `BDT 1,50,000`. No other code converts digits or groups numbers.
  Identifiers (phone numbers, licence numbers, years) get localized digits but never grouping;
  booking references stay Latin.
- **Prices come from `@bhabaghure/pricing` only.** Cards, the detail modal and the booking form
  call the same functions, so they can't disagree.
- **No hardcoded design values.** Colours, sizes, radii and shadows come from
  `packages/tokens/theme.css`. Tailwind's default palette and scales are switched off there; if a
  value is missing, add it to the theme rather than using an arbitrary `[…]` class.
- **Money comes from one source.** Screens read totals from the booking or invoice; they never
  recompute or hardcode them.
