# Trip profit: each trip's costs, collected and profit (2026-10-31)

Admin → **Finance → Trip profit** (`/trip-profit`). A simple, hand-kept costing sheet per trip, asked for by the client:
what the flights, the land package and everything else cost, what was collected from the travellers, and the profit.

**Standalone on purpose.** It reads nothing from bookings, invoices, payments or the chart of accounts, and writes nothing
to them. Staff type each trip in; changing a booking never changes a trip here, and the reverse.

## 1. What is kept (`trip_profits`, migration `2026_10_31_100000`)

| Field | |
|---|---|
| Trip title / package name | required, up to 160 characters |
| Trip type | optional: Group tour, Custom trip, Other |
| Trip date | the day the trip leaves (the client's choice of a start date); the month filter uses it |
| Travellers (pax) | 1–9,999 |
| Flight cost, Land package cost, Other cost | taka with paisa, never negative; left empty = 0 |
| Total collected | required; everything received from the travellers |
| Notes | optional |

**Worked out by the API on every save** (`App\Models\TripProfit`), in whole paisa so nothing is lost to rounding, and kept:
`Total cost = flight + land package + other`, `Profit = collected − total cost` (negative = a loss). What a form sends for
either is ignored. The form shows the same sums live as the figures are typed, plus the profit per traveller.

## 2. The screen

- **Period** (this month by default; every month that has a trip, each year, All time), **search** by title, **type**.
- **Totals for the filter:** trips (and travellers), collected, trip costs, net profit (green, or red for a loss).
- **List**, newest date first, 50 a page: trip (type, notes), date, pax, total cost — click it for the flight / land / other
  breakdown — collected, profit (green / red), Edit, Delete.
- **Add trip / Edit:** one window, with Total cost and Profit worked out as you type.
- **Delete** asks first; the trip leaves the list and the totals, and the row and the audit log keep what it was.
- **Download CSV:** the filtered list — every trip, not one page — with each cost, collected, profit, notes and a totals
  row; opens in Excel (UTF-8 with BOM, so Bangla titles show). A title starting with `=`, `+`, `-` or `@` is written as text.

## 3. Who

`trip_profit.view` (the screen and the CSV) and `trip_profit.manage` (add, edit, delete) — both given to the **Admin**
role; the super admin has everything (the client, 2026-10-31). On live that is the owner today. Anyone else can be given
either in Staff → Roles. New permissions reach existing roles through `permissions:sync --add-only` on deploy.

Audited: `trip_profit.created`, `.updated` (before and after), `.deleted`, `.exported`.

## 4. API

`GET /admin/trip-profits?period=all|YYYY|YYYY-MM&search=&type=&page=` (the trips, `summary`, `months`),
`GET /admin/trip-profits/export?…` (CSV), `POST /admin/trip-profits`, `PUT /admin/trip-profits/{id}` (the whole trip:
an empty cost or type is cleared), `DELETE /admin/trip-profits/{id}`.

## 5. Checked

- API: `TripProfitTest` — the sums in paisa (a loss too) whatever the form sends, refused values, the month / year / search
  / type filters and their totals, `%` searched as a character, delete leaving the totals, the CSV (BOM, Bangla, formula as
  text, totals row), who may see and change.
- Admin: `admin/e2e/trip-profit.spec.ts` — add with live totals, the list and breakdown, the CSV, a loss in red, delete;
  an accountant sees no menu item and no page. On this PC the first sign-in after the e2e database is rebuilt sometimes
  times out while memory is short; it passed cleanly (both tests, first try) on 2026-10-31.
