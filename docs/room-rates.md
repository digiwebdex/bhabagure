# Room rates: triple is the base, twin and single add a percentage (2026-09-28)

**Asked (the client):** for a regular package, three people sharing one room (**triple sharing**) pay the base package
price, with no change. One person alone in a room (**single**) pays **+15%**. Two sharing a room (**twin**) pay a little
more. So the price changes only for twin and single rooms.

**Decided (the client's answers, 2026-09-28):**
- **Twin (+%) replaces the "Triple (−%)" field** on the package form.
- The percentages are **per package**, as the group tours' were.
- **Triple sharing is the base**, twin is not.
- Staff set a lone traveller's price themselves: a price-by-group-size table's 1-person price stays as entered.

## How it works

**Admin → Packages → a package → Room rates** (every package, customized trip or group tour):
- **Twin sharing (+%)**: 0 = the same as triple. Existing packages start at 0.
- **Single room (+%)**: customized trips start at **15%**, group tours keep what they had.
- A preview shows the room's price per person on the package price.

**Price per person:**
| Room | Price per person |
|---|---|
| Triple sharing | the base: the package price after the group-size discount, the price-by-group-size table's price for that group size, or a group tour's fixed price |
| Twin sharing | base + the package's twin % |
| Single | base + the package's single %; with a size table, one traveller pays the 1-person price as entered (it already includes the single room) |

The room charge is its own line on the booking and invoice: **"Twin sharing supplement"** or **"Single room supplement"**.
It is stored in `single_supplement_amount`, the column for the room charge.

**Website:**
- Cards and the size chips show the base (triple sharing); a group tour's card says "triple sharing · fixed price".
- The package page lists each room's price per person for the chosen number of travellers.
- The booking form's room choice shows each room with its percentage and price: *Triple sharing · ৳ 70,500*,
  *Twin sharing (+5%) · ৳ 74,025*, *Single (+15%) · ৳ 81,075*.
- The review and payment steps name the supplement: "Twin sharing supplement (+5%)" or "Single supplement (+15%)".

**Office:** New booking and the quotation editor show each room with its price per person. Editing a booking's
travellers or room re-prices it with the rates it was booked with.

**Bookings made before 2026-09-28** have no room rates stored. They keep the old rule when staff re-price them: single
adds the site-wide supplement (Admin → Pricing), twin costs the same as triple. That site-wide figure is also what the
website's FAQ quotes.

## Data

- `tour_packages`:
  - adds `twin_supplement_percent` (default 0)
  - `single_supplement_percent` now defaults to 15, and customized packages were set to 15
  - `triple_discount_percent` dropped (no package used it)
- `bookings` and `quotations`: `group_tour` becomes `room_rates` (`{singleSupplementPercent, twinSupplementPercent}`) and
  `fixed_price` (a group tour).
- Pricing: `@bhabaghure/pricing`'s `quoteBooking({ rooms, fixedPrice })`, `roomSupplement` and `roomPrices`, and the PHP
  twin `PricingService`. Both are held to the shared `quoteBookingRooms` fixtures (group tours and customized trips,
  with and without a size table, rounding, and the old rule).

## Tests

- `packages/pricing` and `api/tests/Unit/PricingServiceTest`: the shared room-rate fixtures.
- `api/tests/Feature/GroupTourTest`:
  - the fixed price with twin and single
  - the supplement line's name
  - rates kept on a booking
  - a customized trip's group rate as the base
  - the editor fields
  - the brochure
- Totals in `BookingLedgerTest`, `InvoicePdfTest`, `QuotationTest` and `PriceGridTest` now use the package's 15% single.
- `web/e2e/website.spec.ts`: the package page's room prices for four travellers.
- `web/e2e/live-data.spec.ts` "a group tour…": the room list.
- `admin/e2e/group-tours.spec.ts`: the Twin (+%) field and its preview.
