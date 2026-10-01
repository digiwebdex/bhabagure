# Fixed-departure group tours (2026-09-27)

> **Updated 2026-09-28 (docs/room-rates.md):** every package now has room rates. Triple sharing is the base price; **Twin (+%)** replaced the "Triple (−%)" discount below, and single keeps its own +%.
>
> **Updated 2026-10-01 (docs/departure-prices.md):** each departure can have its own price per person, and one is
> featured on the card; the card, the package page and the booking form start on it, and every booking is priced on
> the date it travels. "One fixed price" below now means one price per date.

**Asked:** as on the old website, some trips are **Group Tours** on a **Fixed Departure**, others **Customized Trips**.
For a group tour the agency fixes the trip: the price stays the same, and the customer only picks the room —
**Twin sharing**, **Single (+50%)** or **Triple sharing** — which changes the price. The departure date is fixed: the
customer can't pick a date from a calendar, because the group leaves on that day.

## 1. What existed

- Packages had a **group mode** (`group` showed a "Group tour" badge; `any` = any group size) and a **departure mode**
  (`regular`, `any_date`, `on_request`) — shown as text on the card ("Departure: Nepal 01 · regular departures").
- **Scheduled departures** per package (Admin → package → Group departures: date, seats, status) feed the home page's
  "Upcoming departures" section, and a booking on one of those dates takes seats (refused when full).
- **The booking form always showed a free calendar**, whatever the package.
- **Rooms:** twin, triple and single for every package; single adds the site-wide supplement (12%, Admin → Pricing);
  triple costs the same as twin.

## 2. Decided (2026-09-27, the recommended answers)

1. **Triple sharing** — the same as twin by default, with a discount set per tour.
2. **Single** — +50% by default, set per group tour. Customized trips keep the site-wide supplement (Admin → Pricing).
3. **Dates** — a group tour has one or more fixed departures. One is shown as a fixed date; several are a short list;
   a departure without room for everyone can't be picked ("Sold out" / "Only 2 left"). Never a calendar.
4. **Mustang** — the published Mustang tour (**Nepal M02**) becomes a group tour; the client adds its dates in
   Admin → Packages → the package → Group departures. Until a date is added it can't be booked online (the booking form
   says "No departure dates are scheduled yet — ask us on WhatsApp").

## 3. How it works

**Admin → Packages → a package → Trip type:**
- **Customized trip** (every package until changed) — as before: any date, the group-size discounts, the hotel grid,
  the site-wide single supplement.
- **Group tour · fixed departure** — one fixed price per person (the sale price, else the regular price) for twin
  sharing, whatever the group size; **Single room (+%)** (default 50) and **Triple sharing (−%)** (default 0 = same as
  twin), each with its price previewed. The hotel-category grid is kept but not used while it is a group tour. The
  package list shows a "Group tour · fixed departure" badge.

**Website:**
- Cards and the package page say **"Group Tour · Fixed Departure"** with the next departure ("Nepal M02 · departs
  15 October 2026", or "dates coming soon"), or **"Customized Trip"**. A group tour's card price says "twin sharing ·
  fixed price".
- The package page shows **Price per person by room** (twin, single, triple) and **Fixed departures** with seats left
  or "Sold out", instead of the group-size chips and table.
- The booking form shows the fixed date, or the short list of departures — no calendar — and each room with its price
  per person ("Single (+50%) · ৳ 1,12,500 per person").

**Office:** the New booking form and the quotation editor offer only the tour's departures (a quotation may leave the
date for later) and show each room's price; with no departure the New booking form says to add one first.

**API:** `tour_packages.trip_type` (`group_fixed` | `customized`), `single_supplement_percent`,
`triple_discount_percent`. A group tour's booking — website, office or converted quotation — must be on one of its
scheduled departures with seats, else it is refused on `travel_date`. Bookings and quotations keep the room prices they
were priced with (`group_tour`), so a later change on the package never re-prices them. The brochure PDF lists the
room prices and the departure dates.

**Pricing** is in `@bhabaghure/pricing` (`quoteBooking({ groupTour })`, `groupTourRate`, `groupTourRoomPrices`) and its
PHP twin `PricingService`, held to the shared `quoteBookingGroupTour` fixtures.

## 4. Tests

- `packages/pricing` and `api/tests/Unit/PricingServiceTest` — the shared group-tour fixtures.
- `api/tests/Feature/GroupTourTest` — fixed price, room prices, departures only (website and office), booked prices
  kept, the editor's fields, the brochure.
- `admin/e2e/group-tours.spec.ts` — mark a package, book it at the office on its departure in a single room.
- `web/e2e/live-data.spec.ts` "a group tour…" — card label and price, room prices and departures on the package, no
  calendar, a full departure can't be picked, the gateway charges the single-room total.
