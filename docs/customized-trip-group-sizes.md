# Customized trips: price by group size (2026-09-27)

**Asked:** a Customized Trip says so on the card. The customer picks how many travel — 2, 4, 6, 8, 10, 12 — and the
price per person changes as in the tour brochure's price list (two people pay more each than four, four more than
six). Staff enter the price per person for each group size in the Admin Panel. Today "Book Now" goes to payment at one
price without the customer clearly choosing how many travel.

## 1. What exists

- **Price grid** (Admin → package → price grid, docs/phase-8-visa-quotes-pricing-downloads.md §4.D): price per person
  by hotel category × group size, at the sizes **1, 2, 4, 6, 10**. A size in between pays the smaller size's price
  (3 pays the 2-person price). The 1-person price is required. Live: Bhutan 01/02, Thai 03/04, Maldives 01/02 use it.
- Packages without a grid (Nepal 02/03/04) use the site-wide percentage discounts (Admin → Pricing).
- **Cards** say "Customized Trip" (f299d51) and show the price for the search bar's travellers.
- **Package page:** size chips 1 · 2 · 4 · 6 · 10+ and a stepper; the price follows.
- **Booking form:** a plain number box for travellers — easy to miss.

## 2. Proposed

- **Sizes 2 · 4 · 6 · 8 · 10 · 12** in the grid (plus 1 for a solo traveller — §3.1). Staff fill the prices per size in
  the package editor; an empty size falls back to the smaller one.
- **Booking form:** the travellers box becomes a row of size buttons, each with its price per person
  ("4 জন · জনপ্রতি ৳ 33,000"), chosen before anything else; the total follows. The card's Book Now opens the form on
  this choice.
- **Package page and cards:** the chips become 2 · 4 · 6 · 8 · 10 · 12 with their prices.
- Pricing stays in `@bhabaghure/pricing` and its PHP twin with shared fixtures; bookings keep the size row they were
  priced with.

## 3. Decided (2026-09-27, the recommended answers)

1. **One traveller** can book: the 1-person price stays in the table.
2. **Odd sizes** are allowed at the smaller size's price (5 pay the 4-person price; 14 the 12-person price).
3. **Nepal 02/03/04** keep the site-wide percentage discounts until their size prices are entered.
4. **Sizes:** 1 · 2 · 4 · 6 · 8 · 10 · 12.

## 4. Built

- `GRID_TIERS` = 1, 2, 4, 6, 8, 10, 12 in `@bhabaghure/pricing` and `PricingService` (shared fixtures for 8, 9, 11, 12,
  20 travellers). The admin price table ("Price by group size and hotel category") gains the 8 and 12 columns; a
  package with only Basic / 3-star filled is a simple size table. The brochure prints the same columns.
- Website package page: the chips are the table's sizes (a package without a table keeps its discount steps
  1 · 2 · 4 · 6 · 10+).
- Booking form (`GroupSizePicker`): "How many are travelling?" with every size and its price per person, above the
  rest of the first step; the Travellers box stays for other counts. Group tours keep their fixed price.
- Tests: `PriceGridTest` (8/12 saved, 8, 9 and 14 travellers priced), `web/e2e/live-data.spec.ts` "a customized trip…".
