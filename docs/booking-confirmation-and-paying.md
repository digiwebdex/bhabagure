# Booking confirmation and paying by hand (2026-09-19)

The client reported five problems with booking and paying on the website. What each was, and what changed.

## 1. "Confirm booking" seemed to do nothing, and customers were booked twice

**Cause.** On live the online checkout is off (SSLCommerz credentials not set), so "Confirm booking" saves the booking
and sends the customer to the booking's own page, which shows how to pay. The booking form, though, is a dialog kept
open by the booking store, and navigating did not close it: the booking page loaded *underneath*, the dialog stayed on
top at its payment step, and because the payment step was drawn afresh it had forgotten the booking it had just made —
its button was live again. Customers saw the same screen, clicked again, and a second booking was made. No test covered
the checkout-off path; every test ran with the checkout on.

**Fix.**

- The booking made is kept in the booking store (`created`), not in the payment step, so drawing the step again cannot
  lose it; the button ignores clicks while a booking is on its way.
- Once made, the form **closes** (`finish()`, which also lets go of the travellers' details) and the booking page opens
  with a green **"Congratulations! Your booking is done / অভিনন্দন! আপনার বুকিং সম্পন্ন হয়েছে"** box — the booking
  number and "pay below to secure your seats" — above the ways to pay. It shows for the booking made in this tab, for
  half an hour (`markJustBooked` / `isJustBooked`, sessionStorage). The wording says *done*, not *confirmed*: on this
  site a booking is confirmed by its payment, and the page's own heading says so.
- **Server-side guard.** Each opening of the form gets a UUID (`attemptKey`), sent as `idempotency_key` with the
  booking. `bookings.idempotency_key` is unique: the same attempt sent again — a double click, a retry after a lost
  answer, two requests at once — gets `409 already_created` with the booking number instead of a second booking. The
  access token is not sent again (only its hash is kept); the form that made the attempt still has it and opens the
  booking, or, if it cannot, tells the customer the number and to look for the link on WhatsApp or email.

Tests: `MinimalBookingTest::the_same_booking_attempt_sent_twice_is_one_booking`; website e2e "booking while the online
checkout is off" switches the e2e API's checkout off (it reads `api/.env.e2e` on every request), double-clicks, and
checks one booking, the closed form and the congratulations.

## 2. A second bank account (BRAC Bank)

The `payment` site setting held one bank; it now holds a list, **up to three** (`PaymentOptions::MAX_BANKS`). Admin →
Site settings → Payment has "Add bank account" and "Remove this account"; each account needs all its details. The
booking page, the portal, invoices and the WhatsApp/email "how to pay" show every account. A setting saved before the
change (one `bank`) is read as the first account, so nothing on live had to be re-entered. The client enters the BRAC
Bank details themselves.

## 3. The payment form has no reference box

The SSLCommerz payment link opens SSLCommerz's own form, which has no reference field. The website, the messages and the
invoice now say to write the booking number **after the name in the name box**, with an example: *Rahim Uddin
BH-2609-002*.

## 4. bKash is a payment (merchant) number

"সেন্ড মানি করুন / Send money to" became **"বিকাশ পেমেন্ট নাম্বার / bKash payment number"**, the amount line "Pay",
and the note says to choose *Payment* in the bKash app; the bKash charge is described as bKash's payment fee (it had said
cash-out fee). Same wording in WhatsApp, email and invoices (`PaymentOptions::lines`).

## 5. The visa section was missing

It existed — menu link, the search panel's Visa tab, a page per visa, Admin → Visa services — but hid itself because
none of the twelve visas was published, and publishing required a processing time the client had not sent. The client
chose to go live now: the processing time is **optional**; where it is empty the website and the PDF say "জানতে যোগাযোগ
করুন / ask us", and it shows as soon as staff fill it in. The twelve were published on live the same day. The computer
header now has a **Visa / ভিসা** link beside Packages as well (the ☰ sheet already had one); like the others it appears
only while a visa is published, and the header still fits one row at 900px with every link, in both languages.
