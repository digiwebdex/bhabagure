# Custom service bookings (2026-09-19)

**Asked:** beside the fixed packages, a "Custom Service" option in Admin → New booking: type a service's name, set
the prices as needed, and book it.

**Decided with the client:**

- Each item is priced **per person**; the total is the items × travellers, like a package.
- The site's **service charge and VAT (2% today)** is added, like a package; staff can change the rate or give a
  discount on the booking's draft invoice, as for any booking.
- The **travel date is optional** — a visa or a ticket often has none.
- A custom service has **several items**, each with its name and price, and each prints as its own invoice line.

## Admin → Bookings → + New booking

The package list starts with **★ Custom service — your own items and prices**. Choosing it swaps the room, hotel
category and add-ons (a package's) for:

- **Service name** — what the booking is called everywhere a package name would show: the booking list, the booking
  page, the customer's messages and portal, and the invoice.
- **Items** — name and price per person each; "+ Add item" for more, × to remove one.
- **Travel date (optional).**

The total is worked out on screen with `@bhabaghure/pricing`'s `invoiceTotals` and checked by the API with its PHP
twin; a total the staff member did not see is refused (`409 price_changed`), as for packages.

## After booking

The booking's draft-invoice controls work as for a package — travellers, VAT rate, discount — except that there is no
room type, and the booking is re-priced from its **own items as booked** (names and prices kept), never from a package
price or the group discounts. Issuing the invoice copies the items as its lines.

## How it is stored

- `bookings.is_custom` (boolean); `tour_package_id` null; `package_title_en` holds the service name; `travel_start`
  may be null.
- Booking lines of kind `custom`, one per item: `title_en`, `quantity` = travellers, `unit_price` = price per person.
- `POST /admin/bookings` takes `custom: { title, items: [{ title, unit_price }] }` instead of `package_slug`
  (one or the other). `BookingCreator::createCustom` / `customQuote`; `BookingQuoteEditor` re-prices from
  `customItems()`, which the booking page also receives as `quote_inputs.custom_items`.
- The website's own booking form is unchanged: customers book packages; custom services are the office's.

## Tests

- API `StaffBookingTest::the_office_books_a_custom_service_with_its_own_items_and_prices` — pricing and the refused
  total, no date, the lines, re-pricing on the draft invoice, the issued invoice's items, and the validation (a
  package or a custom service, not both; items needed; whole non-negative prices; a package still needs its date).
- Admin e2e `new-booking.spec.ts` — books one through the screen and re-prices it on the booking page.
