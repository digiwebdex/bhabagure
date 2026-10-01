# Departure prices and the date on the card (2026-10-01)

**Asked:**
- In the admin's package editor, a radio button / "Feature on card" beside a group tour's departures.
- The website's package card shows the departure staff ticked, with its date and price, instead of simply the first
  date.
- The booking form lists every departure date; picking another one re-prices the booking at that date's price.

**Decided (the client's answers, 2026-10-01):**
1. **One price per date.** Each departure has an optional *price per person*; blank, it uses the package's price (its
   sale price, else its regular price). The package's **Twin (+%)** and **Single (+%)** apply on top, as on any date
   (docs/room-rates.md). The date's price is used everywhere: the website, the booking page, office bookings,
   quotations and the brochure.
2. **Next open date.** Once the featured date has departed or filled up, the card shows the next date with seats until
   staff feature another, so it never advertises a date that can't be booked.
3. **Group tours only.** Fixed-departure group tours, where the customer picks from a list of dates. Customized trips
   keep their any-date calendar and group-size prices: their departures have no price and no "Feature on card".
4. **The featured date first.** Book on a card opens the booking form on the date and price the card showed.

## How it works

**Admin → Packages → a group tour → Group departures:**
- Each departure says its price: "BDT 80,000 per person", or "Package price · BDT 75,000 per person".
- Editing a departure: **Price per person on this date**. Leave it blank for the package's price; the hint names that
  price.
- **Shown on the website's card:**
  - a radio beside each date;
  - **Automatic: the next date with seats** (the default).
  Choosing one takes the mark off the others: one date a package. The chosen date carries an "On the card" badge.

**Website:**
- **The card** names the shown date ("departs 10 December 2026") and shows its price. The shown date is the featured
  one while it has seats, else the next date with seats.
- **The package page:**
  - lists each date with its price when the prices differ;
  - prices the rooms for the shown date ("For the departure on …").
- **The booking form:**
  - starts on the shown date;
  - lists every date with its price and seats left ("15 October 2026 · from ৳ 75,000 per person · 12 seats left");
  - picking another date re-prices the rooms, the total and any coupon.
  If the shown date has too few seats for the travellers entered, the form starts on the next date with room.
- **Upcoming departures** (home page) shows each date at its own price.

**Office:**
- The New booking form and the quotation editor list each date with its price.
- The quote follows the date chosen. A quotation with its date left for later is priced at the package's price.

**Brochure PDF:** each date with its price when they differ, and the room table for the shown date.

## Data and API

- `package_departures.price` (nullable) and `package_departures.is_featured` (at most one a package, kept so by
  `DepartureController`).
- Admin:
  - `PUT departures/{id}` takes `price` and `is_featured`;
  - `POST departures/{id}/feature` (`featured`) is the radio.
- Public: `GET /public/departures` adds `price` (null: the package's) and `featured`.
- `BookingCreator::listPrice($package, $travelDate)` prices every booking, website or office, plus the coupon check
  (`POST /public/coupons/check` takes `travel_date`) and quotations (`QuotationService`). A group tour on a departure
  with a price takes that price. Bookings and quotations keep the price they were priced with (`list_price`).
- Office booking options (`GET admin/bookings/options`): each departure's `price`.
- Website: `shownDeparture()` (web/src/lib/departures.ts) picks the shown date. A group tour's `listPrice` in the views
  is that date's price. `pricedOn()` prices the booking form on the chosen date.

## Tests

- `api/tests/Feature/DeparturePricesTest`:
  - a date's price and the featured date, and what the website is told;
  - bookings (website and office), the coupon check and the office options priced by the date;
  - the brochure.
- `admin/e2e/group-tours.spec.ts`: a date's price and the card radio in the editor; the New booking total for each date.
- `web/e2e/live-data.spec.ts` "a group tour shows the featured date…":
  - the card's date and price;
  - the package page's prices;
  - the booking form starting on the featured date and re-pricing on another;
  - the gateway charging the featured date's total.
