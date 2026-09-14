# Bhabaghure admin

Staff app → `admin.bhabaghure.com.bd`. React 19, Vite, Tailwind v4 (tokens from `packages/tokens`), React Router,
TanStack Query, TipTap.

Phase 4 adds notifications: the WhatsApp and email connection, templates with a server-rendered preview, sales alert
recipients, the message log, the booking page's WhatsApp & email card with Send WhatsApp, and My profile for
verifying a staff member's own WhatsApp number ([`docs/phase-4-whatsapp.md`](../docs/phase-4-whatsapp.md) §6).
Phase 3 adds bookings: the draft quote, the invoice preview with header on/off, Print and PDF, Record payment and
status changes ([`docs/phase-3-booking.md`](../docs/phase-3-booking.md) §0). Phase 2 contains the website CMS: packages (with photos and departures), pricing and add-ons, blog, team, reviews,
gallery, media library and site settings. What each screen does and which permission it needs:
[`docs/phase-2-cms-api.md`](../docs/phase-2-cms-api.md) §5 and §7.

## Run it

```bash
cp .env.example .env.local     # VITE_API_URL=http://localhost:8000
npm run dev --workspace admin  # http://localhost:5173
```

The API must be running (`api/README.md`) and list `http://localhost:5173` in `CORS_ALLOWED_ORIGINS`. For a login,
run `php artisan db:seed --class=DevStaffSeeder` in `api/` (local only; passwords are printed once).

## Checks

```bash
npm run lint --workspace admin
npm run build --workspace admin
npm run test:e2e --workspace admin   # against a separate API on :8001 and the bhabaghure_e2e database
```

## Rules

- **Language:** every string is in `src/i18n/bn.json` and `en.json`; Bangla is the default. The choice made on a
  device (login screen or sidebar) sticks.
- **Numbers and dates** only through `useFormat()` (`@bhabaghure/format`). Inputs accept Bengali digits.
- **Prices** in examples come from `@bhabaghure/pricing`, the same code as the website's booking form.
- **The API decides** permissions, validation and publish checklists; the admin mirrors them for guidance only.
- **Tokens:** the access token is kept in memory, never in storage; the refresh token is an httpOnly cookie.
