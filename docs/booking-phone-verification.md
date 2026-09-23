# A code to the customer's mobile before a website booking is saved (2026-09-24)

**Asked:** to stop fake bookings, send a one-time code to the customer's mobile just before they confirm a booking on
the website. Only with the right code is the booking saved (so it appears in the admin's list), and only then does the
customer go on to the payment page.

**Decided with the client (2026-09-24):** built with an on/off switch, **left off until SMS works**, so website bookings
keep working meanwhile. The client adds 187.77.144.38 to the bulksmsbd IP whitelist; once a test code arrives, the
switch goes on.

## 1. What exists

- **One-time codes already exist:** `LoginCodes` sends the customer portal's sign-in codes and the phone-change codes —
  six digits, valid 10 minutes, five tries, only the newest code works, stored as an HMAC, never in the message log.
  Limits per number (one a minute, five an hour) plus a per-address route limit. Each code has a *purpose*, so a
  sign-in code can't be used for anything else. SMS first; WhatsApp only as a fallback (switched off on live).
- **The booking form** saves the booking at step 4 ("Confirm booking" / "Pay … with SSLCommerz"); the booking page then
  shows how to pay.
- **SMS does not work on live — no SMS has ever been delivered from the server.** bulksmsbd refuses every message with
  code 1032 (the server's address, 187.77.144.38, isn't on the account's IP whitelist): every portal sign-in code since
  launch shows `channel = none`, the latest on 2026-09-21. Until the client adds the address in the bulksmsbd panel, no
  code can reach a customer.

## 2. Design

**Reuse, not a second code system:** a new purpose, `booking`, for `LoginCodes` — same code, limits and storage.

**The customer's side (website, step 4):** "Confirm booking" first sends a code to the lead traveller's mobile (the
number they typed in step 2) and opens a code box in the same step: *"We sent a 6-digit code to 01711-XXXXXX. Enter it to
confirm your booking."* — with "Send a new code" (after 60 s) and "Change number" (back to the travellers). The right code
saves the booking and goes on exactly as today (the booking page, or SSLCommerz). A wrong or expired code says so; the
booking is not saved. If the code can't be sent, the customer is told to call or WhatsApp the office.

**The API:**

- `POST /public/booking-codes` `{ phone, locale }` → 202 sent · 429 wait (with seconds) · 503 can't be sent.
- `POST /public/bookings` takes `verification_code`. While the check is on: none → `422 verification_required`; wrong,
  expired or out of tries → `422 verification_invalid`; right → the booking is saved and the code used up **in the same
  transaction**, so one code makes one booking.
- `bookings.phone_verified_at` records it; the admin's booking page shows "Mobile verified" (or not, for office bookings
  and older ones).

**The switch:** Admin → Site settings → *Website booking*: "Check the customer's mobile with a code before a booking is
saved", with the SMS status beside it (when a code last reached a customer, or that none ever has). Served to the
website with the pricing settings; the API enforces it either way. Office bookings (staff) never need a code.

## 3. How it is built

**New.**

- API: `Services/Booking/PhoneCheck` (reads the switch, site setting `booking` → `verifyPhone`, off when absent),
  `Services/Booking/VerificationInvalid`; migration `2026_09_24_140000_add_phone_verified_at_to_bookings.php`.
- Website: `features/booking/PhoneCodeBox.tsx` (where the code went, the box, "Send a new code" after the wait,
  "Change number").

**Changed.**

- `LoginCodes`: purpose `booking`, its SMS text (Bangla or English, as the site was used), its own staff alert when
  neither SMS nor WhatsApp can send it, and `deliveryStatus()` — when a code last reached a customer and when one last
  failed.
- `PublicBookingController`: `code()` (the route `booking-codes`, 404 while the check is off) and the check in
  `store()`, after the double-submit check (a repeated click still opens the booking already made) and before anything
  is saved. `BookingCreator` uses the code up inside the booking's transaction (locked, so two requests with one code
  make one booking) and stamps `phone_verified_at`; the customer's number counts as verified too, as after a portal
  sign-in. Office bookings go through the same creator without a code.
- Settings: key `booking` (staff-editable, audited, refreshes the website's `settings` cache);
  `GET /admin/settings` also returns `meta.codes`; `/public/pricing` carries `verifyPhone`.
- Admin: Site settings → *Website booking* (the switch; beside it, a warning while no code has reached a customer or
  the last one failed); the booking page's Travellers card shows "✓ Mobile verified" with the time.
- Website: the payment step (`PaymentStep.tsx`) sends the code on the first click and books with it on the next; a page
  cached before the switch went on learns it from the API's `verification_required` and carries on the same way. A
  number that had a code a moment ago is told to use that one (the API's one-a-minute limit), not refused.

## 4. Tests

- **API** `BookingPhoneVerificationTest`: switched off, booking is exactly as before and the code route is 404; on — no
  code and a wrong code book nothing; the SMS says what the code is for (Bangla too), is stored as an HMAC and stays out
  of the message log; the right code saves one booking, marked verified, which the admin sees; a price change doesn't use
  the code up; the same attempt again opens the same booking; the code can't make a second booking; a sign-in code,
  another number's code, an expired code and a sixth try are refused; one a minute per number (429 with the wait), a
  bad number 422, SMS failing → 503 and one staff alert; office bookings never need a code; the switch's rules, audit
  and who may change it.
- **Admin e2e** `booking-verification.spec.ts`: the warning while no code has gone out, the switch on, a booking made
  with a code, "codes are reaching customers", the switch off, "✓ Mobile verified" on the booking.
- **Website e2e** `booking-verification.spec.ts`: a page from before the switch went on — the API asks, the code goes
  out, an empty and a wrong code are refused with nothing saved, the right code books (verified) and goes on to the
  SSLCommerz stand-in; then, with the site refreshed, the first click sends the code, "Change number" goes back to the
  travellers, and coming back the code sent a moment ago still books.

## 5. Switching it on

1. The client adds 187.77.144.38 to the IP whitelist in the bulksmsbd panel.
2. Sign in to the customer portal with a real number: the code must arrive. Admin → Site settings → *Website booking*
   then says "Codes are reaching customers" (any code counts — sign-in, number change or booking).
3. Switch it on and save. The website follows at once (its cached settings are refreshed).

If codes stop arriving later, staff get the alert email, and the settings card shows the failure — switch it off until
SMS works again, or nobody can book on the website.
