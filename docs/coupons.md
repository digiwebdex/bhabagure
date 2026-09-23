# Coupons and promotional discounts (2026-09-24)

**Asked:** coupon codes on the website's booking form (apply, see the discount, remove), coupon management and a usage
report in the admin, two kinds of coupon — **passport-specific** (one passport holder) and **public** (a campaign code)
— percentage or fixed discounts, checked and worked out only on the server, carried through the booking, the invoice,
payments and the books, and every use recorded.

**Decided with the client (2026-09-24):**

- A passport coupon works when its passport belongs to **any traveller on the booking** — the holder must travel, but a
  family member may make the booking.
- A coupon use on a **confirmed** booking **stays used** if the booking is later cancelled. An unpaid booking that is
  cancelled (or deleted) gives its use back.
- **Admins** create, edit and archive coupons and see the report; **accountants** see coupons and the report. The super
  admin sees everything; the Roles screen can change the rest.

## 1. What exists today (audit)

| Area | What is there | What a coupon needs from it |
| --- | --- | --- |
| Pricing | `@bhabaghure/pricing` (website, admin) and its PHP twin `PricingService`, held to `packages/pricing/fixtures.json`. `invoiceTotals`: **discount first, then the 2% service charge/VAT on what is left** (decided 2026-09-13). `discount` is already an input — "a staff member's discount; the website never sends one". | A coupon is a discount: it goes in the same place. One new shared function, `couponDiscount`, with fixtures, so the admin screen and the API cannot disagree. |
| Website booking | `BookingModal` → steps package · travellers · review · pay. The API re-prices (`BookingCreator`) and refuses a total the customer did not see (`409 price_changed`). One attempt = one booking (`idempotency_key`). Passport numbers are optional, collected in the travellers step. | Coupon box on the review step; `coupon_code` sent with the booking; the API re-checks the coupon inside the booking's transaction. |
| Booking | `bookings.discount_amount` (a CHECK keeps `total = lines − discount + VAT`), `due_amount` generated, paid/payment status only from the ledger, status only through `BookingStateMachine` (inquiry → confirmed → completed, or cancelled; confirming needs money on the books). Draft-invoice controls (`BookingQuoteEditor`: travellers, room, VAT, discount) until the invoice is issued. | The coupon's share of the discount stored beside the total discount, so the staff discount and the coupon can be told apart and the CHECK still holds. |
| Passports | `booking_travellers.passport_number` encrypted, with an HMAC `passport_number_hash` for lookups (`BookingTraveller::passportHash`). | A passport coupon stores its passport the same way and matches on the hash — nothing is decrypted to check a coupon. |
| Customers | One customer per phone number; the website finds or creates it from the lead traveller's WhatsApp number. | "Per customer" = per customer record. |
| Invoice | Issued as a frozen snapshot (`Invoice::SNAPSHOT_COLUMNS`) from the booking; prints Subtotal · Discount · Service charge & VAT · Total · payments · Amount due (English only). | Coupon code and coupon discount added to the snapshot and printed as their own line. |
| Payments, due, books | `LedgerService` is the only writer of money. Issuing posts Dr Receivable (total) · Cr Package sales (total − VAT) · Cr VAT payable; payments settle the receivable; due = total − paid. | **Nothing changes.** The invoice total is already net of the discount, so sales, receivable, payments and due all follow the discounted amount, as they do today for a staff discount. |
| Admin access | Spatie permissions per module (`RolesAndPermissionsSeeder`), the Roles screen, `permissions:sync --add-only` on deploy; the sidebar in `admin/src/app/navigation.ts`. | New module **Marketing**: `coupons.view`, `coupons.manage`. |
| Existing discounts | Group-size slabs and sale prices are prices, not discounts; the staff discount on the draft invoice; nothing else. No coupon, promotion or voucher code exists anywhere. | One coupon per booking. It comes on top of the group price and the sale price; staff can still add their own discount on top. |

## 2. Design

### 2.1 Tables

**`coupons`**

| Column | |
| --- | --- |
| `code` | unique, stored in capitals; letters, digits and `-`, 3–30 long. Unique **including archived coupons**, so an old code never points at a new offer. Case does not matter to customers. |
| `name` | coupon / campaign name. Coupons sharing a name are one campaign in the report. |
| `kind` | `public` or `passport`. |
| `channel` | optional: website, Facebook, SMS, email, seasonal, other — for campaign performance. |
| `discount_type`, `discount_value` | `percent` (up to 100) or `fixed` (whole taka). |
| `max_discount_amount` | percent coupons only: the most one booking saves. |
| `min_booking_amount` | optional: the booking amount the coupon needs (see 2.2). |
| `starts_at`, `ends_at` | optional; entered in Dhaka time, stored in UTC. Valid from `starts_at` until `ends_at`. |
| `usage_limit`, `per_customer_limit` | optional (blank = no limit). A new coupon's form starts at 1 per customer. |
| `applies_to` | `all` (every booking, packages and the office's custom services) or `packages` (only those in `coupon_packages`). |
| `passport_number` (encrypted), `passport_number_hash`, `holder_name` | passport coupons only; `holder_name` is for staff (who it was given to). |
| `is_active`, `notes`, `created_by_staff_id`, `updated_by_staff_id`, timestamps, `deleted_at` (archive) | |

**`coupon_packages`** — `(coupon_id, tour_package_id)`: the packages a coupon with `applies_to = packages` works on. A
coupon limited to packages that has none left works on nothing — never, by accident, on everything.

**`coupon_redemptions`** — every use, and the record the report reads. It keeps its own copy of the terms, so a booking's
discount never depends on what the coupon says later.

| Column | |
| --- | --- |
| `coupon_id`, `booking_id`, `customer_id` | foreign keys (restrict). |
| `code`, `kind`, `discount_type`, `discount_value`, `max_discount_amount`, `min_booking_amount` | the terms as they were when applied. |
| `passport_number` (encrypted), `passport_number_hash` | the passport that satisfied a passport coupon. |
| `eligible_amount`, `discount_amount`, `original_total`, `final_total` | the booking amount the coupon worked on, the discount, the booking's total without and with the coupon. Kept in step with the booking while its quote can still change. |
| `status` | `reserved` → `used` → (`released`); see 2.4. |
| `source`, `applied_by_staff_id`, `applied_at`, `used_at`, `released_at`, `release_reason`, `released_by_staff_id` | |

A generated column holds `booking_id` only while the use is reserved or used, and is unique: **one live coupon per
booking**, enforced by the database. Indexes on `(coupon_id, status)`, `(customer_id, coupon_id)`,
`passport_number_hash`, `applied_at`.

**`bookings.coupon_discount_amount`** — the coupon's share of `discount_amount` (CHECK: between 0 and
`discount_amount`). `discount_amount` stays the whole discount, so the existing total CHECK and everything that reads it
are untouched.

**`invoices.coupon_code`, `invoices.coupon_discount_amount`** — part of the frozen snapshot.

### 2.2 How the discount is worked out

On the **booking amount**: every line (package, single room, add-ons; a custom service's items) before any discount and
before the service charge — the amount the existing discount rule already works on.

- **Percent:** booking amount × rate, rounded to whole taka, capped at the maximum discount if one is set.
- **Fixed:** the amount.
- Never more than the booking amount, so a total can't go below zero (the service charge on zero is zero).
- The minimum booking amount is compared with the same booking amount.
- Then the 2% service charge/VAT is worked out on what is left, as for every discount today. Example: a booking of
  ৳10,000 with TRAVEL10 → discount ৳1,000 → service charge 2% of ৳9,000 = ৳180 → **payable ৳9,180**.

`couponDiscount()` in `@bhabaghure/pricing` and `PricingService::couponDiscount()` do this, with shared fixtures.

### 2.3 The checks, in order, and what the customer reads

1. The code exists and is not archived — *"This coupon code isn't valid."*
2. It is active — *"This coupon isn't active."*
3. It has started — *"This coupon can be used from 1 October 2026."*
4. It has not expired — *"This coupon expired on 30 September 2026."*
5. It works on this package — *"This coupon doesn't apply to this package."*
6. The booking amount reaches the minimum — *"This coupon needs a booking of at least ৳20,000."*
7. Passport coupon: the passport is on the booking — *"This coupon is for a particular passport holder. Enter that
   traveller's passport number in the travellers' details."* (The passport it wants is never shown or hinted at.)
8. Uses left in all — *"This coupon has been used up."*
9. Uses left for this customer — *"You have already used this coupon."*

Both languages on the website; English in the admin. The customer never sends a discount: the website sends only the
code. The API works the discount out, and a total that doesn't match what it works out is refused, as today.

### 2.4 Life of a use

| When | The use |
| --- | --- |
| A booking is made with the coupon (website), or staff apply it to a booking (admin) | **reserved** — it counts against both limits straight away, so the last use can't go to two people. |
| Staff change travellers or room on the draft invoice | re-worked from the use's own copy of the terms (not the coupon's current ones); refused, with the reason, if the booking falls below the minimum. |
| The booking is confirmed (its first money is on the books) | **used** — counted in the report's usage and discount given. |
| An unconfirmed booking is cancelled or deleted, or staff remove the coupon from a booking | **released** — it stops counting against the limits; a booking still open is re-priced without it. |
| A confirmed booking is cancelled | stays **used** (decided 2026-09-24); the report shows the booking as cancelled. |

**Race safety:** reserving locks the coupon's row (`SELECT … FOR UPDATE`) and counts its uses with locking reads inside
the same transaction as the booking. Two customers trying for the last use at the same moment: one gets it, the other
gets *"used up"* and can book without it.

An unpaid website booking holds its use until staff confirm or cancel it (there is no automatic expiry of inquiries
today). Cancelling a fake or abandoned inquiry frees the use.

### 2.5 Money

- Booking: `discount_amount` = coupon + any staff discount; `coupon_discount_amount` = the coupon's part; the total,
  due and payment status follow as today.
- Draft invoice controls: the coupon shows as its own line with **Remove**; with no coupon, an **Apply coupon** box. The
  discount field is the staff's *extra* discount on top.
- Invoice: Subtotal · **Coupon discount (TRAVEL10)** · Discount (staff's, if any) · Service charge & VAT · Total ·
  payments · Amount due.
- Payments, due, the cash book and the journal: unchanged, and so correct — they already use the invoice's net total.
- The website's booking page and the customer portal show the coupon line too.

### 2.6 API

Staff (English, snake_case, like the rest of `/admin`):

| | Permission |
| --- | --- |
| `GET /admin/coupons?status=&search=` — list with usage counts | `coupons.view` |
| `GET /admin/coupons/options` — packages for the form | `coupons.view` |
| `GET /admin/coupons/{id}` | `coupons.view` |
| `POST /admin/coupons`, `PUT /admin/coupons/{id}` | `coupons.manage` |
| `POST /admin/coupons/{id}/activate`, `…/deactivate` | `coupons.manage` |
| `DELETE /admin/coupons/{id}` — deleted if never used, otherwise archived | `coupons.manage` |
| `POST /admin/coupons/{id}/restore` | `coupons.manage` |
| `GET /admin/coupon-report?from=&to=&coupon_id=&status=&search=&passport=` | `coupons.view` |
| `POST /admin/bookings/{id}/coupon`, `DELETE /admin/bookings/{id}/coupon` — apply / remove on a booking | `bookings.update` (as for the draft invoice) |

A used coupon keeps its code (it is on invoices); every other field can change, and only future uses see the change.

Website (camelCase, like the rest of `/public`):

- `POST /public/coupons/check` — the booking's choices, the lead's WhatsApp number and the passport numbers entered →
  `{ valid: true, code, kind, discountType, discountValue, maxDiscount, minAmount, discount, subtotal, originalTotal,
  total, message }`, or `{ valid: false, code, reason, message, subtotal, total }` (200 either way; 422 for a malformed
  request, 404 for an unknown package). `subtotal` is the booking amount before the discount; `originalTotal` and
  `total` are the totals without and with the coupon. Throttled per IP (20 a minute, 200 a day) against code guessing.
- `POST /public/bookings` takes `coupon_code`. A coupon that fails at that moment → `409 coupon_invalid` with the
  reason (`reason`, `message`); nothing is booked, and the form takes the coupon off and shows the price without it.
  A total that doesn't match the API's own discount is `409 price_changed`, as before.

### 2.7 Screens

- **Admin → Marketing → Coupons**: status chips (all, active, scheduled, expired, used up, inactive, archived),
  search, a table (code and name, type, discount, validity, usage, status, actions: edit, activate/deactivate, usage,
  delete/archive/restore) and one form for create and edit.
- **Admin → Marketing → Coupon report**: totals (coupons, active, expired, uses, pending, discount given, revenue),
  coupon-wise and campaign tables, passport-coupon uses, and every use, with filters for coupon, dates, customer,
  passport, booking and status. "Usage" on a coupon opens the report filtered to it.
- **Admin → booking → Quote** card: the coupon line, Apply / Remove.
- **Website booking form, review step**: a compact coupon row under the price breakdown (Apply; while checking, a busy
  button; applied, a green line with Remove; refused, the reason). The breakdown gains *Subtotal* and *Coupon discount*
  lines only when a coupon is applied, so a booking without one looks exactly as before. If the customer goes back and
  changes the booking, the coupon is checked again automatically.

### 2.8 Security

Only the permissions above reach coupon data or actions; the server checks every one. Customers can only send a code.
The discount, the passport match and the limits are all decided on the server. Codes are unique in the database, and
negative totals are impossible (the discount is capped, and CHECK constraints guard the booking and invoice). The
passport check compares hashes, and the check endpoint is rate-limited. Every create, edit, activate, archive, apply and
remove is in the audit log. Passport numbers show masked in lists and the report.

## 3. How it is built

**New.**

- API: migration `2026_09_24_100000_create_coupons.php`; models `Coupon`, `CouponRedemption`;
  `Services/Coupons/` — `CouponService` (checks, reserve, used, released), `CouponCheck` (what a code is checked
  against), `CouponRefused` (the reason and the sentence), `CouponManager` (admin writes, audited), `CouponReport`;
  controllers `Admin\CouponController`, `Admin\CouponReportController`, `Public\PublicCouponController`; resource
  `AdminCoupon`; `lang/{en,bn}/coupons.php`.
- Admin: `features/coupons/` — `CouponsPage`, `CouponDialog`, `CouponReportPage`, `api.ts`, `useDescribeDiscount.ts`.
- Website: `features/booking/CouponBox.tsx` and `coupon.ts` (checking, checking again after a change, removing).

**Changed.**

- Pricing: `couponDiscount()` in `packages/pricing` and `PricingService`, with fixtures.
- Booking: `BookingCreator` checks and reserves the coupon inside the booking's transaction; `BookingQuoteEditor` reworks
  it on new lines and applies/removes it (staff); `BookingStateMachine` marks it used on confirm and releases an
  unconfirmed one on cancel; deleting a booking releases it; `Booking::appliedCoupon()`.
- Invoice: `coupon_code` and `coupon_discount_amount` in the frozen snapshot; `InvoiceView` prints the coupon line.
- Access: `coupons.view` / `coupons.manage` (module *Marketing*) in `RolesAndPermissionsSeeder` — admin both,
  accountant view; `permissions:sync --add-only` gives them to the live roles on deploy. Rate limit `coupon-checks`.
  `coupon` added to the morph map (audit rows about a coupon).
- Screens: the Marketing group in the admin sidebar; the booking page's Quote card; the website's review and payment
  steps, booking page and portal trip.

`starts_at`/`ends_at` are DATETIME (UTC), not TIMESTAMP: an admin may set an expiry past 2038, which a TIMESTAMP can't
hold (found by the tests — it was a 500).

## 4. Tests

- **Shared pricing fixtures** (TypeScript `packages/pricing`, PHP `PricingServiceTest`): percent, fixed, the cap, the
  minimum, never below zero, and the coupon before the service charge.
- **API** — `CouponCheckTest` (valid, unknown, switched off, archived, not started, expired, minimum, package, passport
  right and wrong, total and per-customer limits, rate limit), `CouponBookingTest` (the booking keeps the terms it was
  given; a made-up discount or total books nothing; a coupon that stops working refuses the booking; invoice, journal,
  payments and due on the discounted total; used on confirm; released on an unconfirmed cancel or delete, kept on a
  confirmed cancel; staff apply/remove and the minimum on a quote change; passport coupons; no coupon exactly as
  before), `CouponAdminTest` (the form's rules, unique codes, passport masking and encryption, status chips, a used
  code locked, archive/delete/restore, who may do what), `CouponReportTest` (totals, per coupon, campaigns, passport
  uses, every filter), and `CouponConcurrencyTest`: **two PHP processes race for the last use and one gets it** —
  with the lock removed, the test fails (both get it).
- **Admin e2e** `coupons.spec.ts` — create, refuse a bad code, switch off and on, a use held by a website booking,
  archive and restore; apply, refuse, remove and re-apply on a booking, a third traveller and an extra discount, the
  printed invoice, the report and its filters; accountants look, sales agents don't see it.
- **Website e2e** `coupons.spec.ts` — empty, wrong and someone else's passport code refused; applied (Subtotal, the
  coupon line, the charge on what is left); removed and re-applied; checked again after a change; paid through the
  SSLCommerz stand-in at the discounted total; the booking page's coupon line; a coupon switched off before booking is
  taken off and the customer books at the full price.
- Then everything: API suite, pricing tests, type checks, lint, the three production builds, both e2e suites.
