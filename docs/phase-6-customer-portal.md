# Phase 6 — Customer portal (`customer.bhabaghure.com.bd`)

**Status (2026-09-14): approved, being built.**

## 0. Decisions (2026-09-14)

1. **Sign-in:** one-time code only — SMS to the phone on record, WhatsApp as the fallback, no passwords. The first code on a known number claims the existing customer record.
2. **Documents:** uploads with staff review. Customers upload the passport scan and photo per traveller and enter a missing passport number; staff verify or reject; visa and insurance are staff-set statuses shown read-only. Passport digits are never shown back.
3. **Support:** tickets with an admin Support queue. The queue's badge counts tickets unanswered after 24 hours; replies go out by WhatsApp and email and show in the portal.
4. **Loyalty:** NPS only. Loyalty and referral are deferred. NPS is asked after a completed trip, stored and shown to staff; 9–10 get the review link, and 0–6 create a follow-up for the trip's owner.

Kept from the plan, open to veto:
- private pages aren't cached offline (§2 #10);
- the invoice is the receipt (§2 #6);
- customers can accept a quotation, and staff still convert it (§3.2).

**Sources:**
- `_design/Bhabaghure Customer Portal.dc.html` and `_design/README.md` §3, from the local 2026-09-13 copy. The re-synced design still can't be read here; if it changed the portal, this plan is re-checked first.
- The existing customer auth, the booking private link and the web app's portal placeholder.
- Phase 1, 3, 4 and 5 documents.

---

## 1. What exists today

| Area | State | Consequence |
|---|---|---|
| Customer sign-in | Password only (`customer/auth` register, login, refresh, me). Registering a phone or email already on file answers 409 `contact_us`. There is no OTP, no password reset and no verification. | Everyone who has booked has a customer record **without a password**: they can't register and can't sign in. **The portal needs a way to claim an existing record.** |
| Customer record | Unique active phone and email; `phone_verified_at` and `email_verified_at` exist but are never written; no passport or date-of-birth columns (those live per traveller on each booking). | Phone is the identity. Changing it needs re-verification. |
| Booking private link | `/booking/{ref}#t={token}` works for guests; the API also accepts a signed-in owner (`customer_id = me`), but the web client never sends a token. Only the token's hash is stored. | The link can't be re-sent later. The portal replaces it for signed-in customers. |
| Online payment | SSLCommerz for the full balance plus the online charge; inquiry or confirmed bookings with money due. | Reusable from the portal as it is. |
| Passport scans | Anonymous, pre-booking OCR uploads kept 24 h. Nothing after booking. | Documents need a new authenticated, private, per-traveller flow. |
| Web portal | `customer.*` is rewritten to `/[locale]/portal/*`, which is a placeholder with five static tabs. The auth client discards the access token; the session is memory-only. No manifest or service worker. | The session plumbing (token, refresh on load, CORS for the customer host) is built first. |
| Invoices | Share page and PDF on the API host (passports masked). The design links `customer.…/invoice/{token}`, which doesn't exist. | Invoices open from the portal through the existing share page. |

## 2. The design, and what is wrong with it

**Five tabs:**
1. **My trips:** next-trip card with a readiness checklist and countdown, and a trip list.
2. **Documents:** per traveller, four slots — passport scan, photo, visa, insurance.
3. **Payments:** three KPIs and a history with receipts.
4. **Support:** tickets and a new-ticket form.
5. **Profile:** personal details, a loyalty card, a referral code, an NPS question.

| # | Finding | Proposed handling |
|---|---|---|
| 1 | No sign-in screen; a hardcoded avatar; log out does nothing. | Sign-in and account claim (§3.1, question 1). |
| 2 | Readiness is a literal 75 %, and it disagrees with the Documents tab (the photo is missing in one, insurance in the other). | Readiness is derived: paid in full, each traveller's passport number on file, documents verified by staff, e-ticket issued. Only the checks the system knows. |
| 3 | Visa and insurance are drawn as customer uploads, but staff issue them. | Customers upload the passport scan and photo; visa and insurance are statuses staff set, shown read-only (question 2). |
| 4 | The profile shows an editable passport number in full (`BW0912345`). | Passport digits are never shown back. A traveller with no passport number on file can have one entered, per booking (question 2). |
| 5 | "Total paid" sums every booking total, including unpaid upcoming trips; "Due ৳ 0" and "Savings ৳ 18,400" are literals; every history row shows the full booking total. | Paid and due come from the ledger; history comes from the cash book (reversals shown); savings from the booking's discount lines, or dropped. |
| 6 | A receipt per payment, but no such document exists. | The invoice (with its payment lines) is the receipt. No separate receipt PDF. |
| 7 | Trip pills exist only for confirmed and completed. | Inquiry (awaiting payment), confirmed, completed and cancelled, as in the admin. |
| 8 | Loyalty is points in the portal but trips/spend tiers in the admin; referral codes need a coupon engine; NPS has no storage. | Question 4. |
| 9 | Support tickets promise a reply within 24 hours, but there is no ticket table or admin queue (the admin Inbox is deferred). | Question 3. |
| 10 | The offline banner says saved itineraries and invoices still open offline. | **Not built:** private pages aren't cached on the device (a shared phone would keep passport-related data). Offline, the portal says so and offers the office number. This deviates from the design; open to veto. |
| 11 | Bangla only, no language toggle. | Bangla default with the website's English toggle, as elsewhere. |
| 12 | Dates contradict each other (a 5-day countdown to 17 Sep; documents dated 18 Sep; a payment on 22 Sep). | Everything is computed in Dhaka time. |

## 3. Scope, built in this order

### 3.1 Sign-in and account claim (question 1)
- One screen: phone number → a one-time code → signed in.
- The first successful code on a number that already has a customer record **claims** that record and sets `phone_verified_at`. The same flow creates a lead for a new number.
- Neutral responses: the screen never says whether a number is already a customer.
- Limits:
  - per number: one code a minute, five an hour;
  - per IP address;
  - per code: five tries, and it expires after 10 minutes;
  - codes are stored hashed, as for staff WhatsApp verification.
- Email change needs the email to be confirmed. Phone change needs a code on the new number and no clash with another record.
- A disabled flag on the customer (staff can block portal sign-in) and a sign-in audit, both shown on the admin profile.

### 3.2 My trips
- Bookings where the customer is the account holder: upcoming, completed, all.
- A trip page:
  - lines, travellers (names only), dates, status;
  - paid and due, and pay the balance online (existing SSLCommerz flow);
  - invoice link and PDF;
  - the package itinerary days (from the package, labelled as the planned itinerary);
  - the readiness checklist (§2 #2) and the countdown.
- Quotations addressed to the customer:
  - opening one records **Viewed** (the Phase 5 deferral);
  - the customer can **accept** a valid quotation; staff are notified and still convert it;
  - a reminder goes 24 hours before expiry, WhatsApp and email, as a Phase 4 template.

### 3.3 Documents (question 2)
- Per traveller, per upcoming confirmed trip:
  - passport scan and photo uploads, private and encrypted, 5 MB;
  - passport number entry while none is on file;
  - read-only visa and insurance statuses.
- Staff review each upload in the admin (verified or rejected with a reason); the customer sees the result.
- A rejected or missing item shows what to do.

### 3.4 Payments
- KPIs: paid and due across the customer's bookings.
- History from the cash book: date, trip, method, reference, amount, reversals.
- The invoice link per trip.

### 3.5 Support (question 3)

### 3.6 Profile (question 4)
- Name, email (confirmed), address, language, and WhatsApp messages on/off (the existing opt-out).

### 3.7 Admin additions
- On the customer profile:
  - portal status (claimed, last sign-in);
  - block/unblock sign-in;
  - "send portal invite" (a WhatsApp message with the portal address — no link token needed, sign-in is by code).
- A document review queue: uploads waiting, with a sidebar badge derived from data as in Phase 5.
- Whatever questions 3 and 4 add.

## 4. Data model (proposed)

| Change | Purpose |
|---|---|
| `customer_login_codes` | phone, code hash, attempts, expires_at, consumed_at, IP |
| `customers.portal_disabled_at`, `portal_claimed_at` | Block and claim state; `phone_verified_at` finally written |
| `traveller_documents` | booking_traveller_id, kind (passport_scan · photo · visa · insurance), status (missing · uploaded · verified · rejected · issued), file path (private), reason, reviewed_by, reviewed_at |
| `quotations.viewed_at`, `accepted_via` (staff · portal) | Viewed and portal acceptance |
| Question 3 and 4 tables | Only if chosen |

## 5. Security and privacy rules
- A customer sees only records where they are the account holder, with the Phase 3 rule that "not yours" and "missing" both answer 404.
- Passport digits never leave the API towards the portal. Uploads are served back only to the owner and to staff, with no caching.
- Codes and sessions: access token in memory, rotating httpOnly refresh cookie (existing), `CORS_ALLOWED_ORIGINS` gains the customer host.
- Sending codes needs SMS (bulksmsbd) or WhatsApp to be switched on in production. Both are off until you set the keys (`deployment.md` §3–§4). **Until then the portal can't send codes on the live site.** Locally and in tests, the fake gateways are used.

## 6. Tests
- **API:**
  - code limits and expiry;
  - claiming an existing record;
  - neutral responses;
  - owner-only access (404) for trips, documents, payments and quotations;
  - uploads served only to the owner and staff;
  - quotation Viewed and accept;
  - readiness derivation;
  - Dhaka countdown.
- **Web e2e (Playwright on the web app):** sign in with a code → trips → pay link → documents upload → staff verifies in admin → the customer sees Verified; Bangla and English; phone width.

## 7. Questions

1. **How do customers sign in?**
   - **(a) Recommended:** a one-time code only, sent by SMS to the phone on record, with WhatsApp as the fallback when SMS fails. No passwords. Existing records are claimed by the first code.
   - (b) Code to claim, then a password for later sign-ins (with a code to reset it).
   - (c) Keep passwords; add a code only for claiming and reset.
2. **Documents in the portal?**
   - **(a) Recommended:** customers upload the passport scan and photo per traveller and enter a missing passport number; staff verify or reject; visa and insurance are staff-set statuses shown read-only.
   - (b) Statuses only: customers see what is missing and send documents by WhatsApp; staff record them.
   - (c) Not in Phase 6.
3. **Support?**
   - **(a) Recommended:** tickets. A customer opens one about a trip; it lands in a new admin Support queue with a badge for tickets unanswered after 24 hours; replies go back by WhatsApp and email and show in the portal.
   - (b) No tickets: the tab offers call and WhatsApp buttons and shows the messages already sent to the customer.
   - (c) Defer support to the Inbox module.
4. **Loyalty, referral and NPS?**
   - **(a) Recommended:** defer loyalty and referral (the design contradicts itself, and referral needs a coupon engine). Build NPS: one question after a completed trip, stored, shown to staff; 9–10 gets the review link, 0–6 creates a follow-up for the trip's owner.
   - (b) Show a trips/spend tier as the admin design defines it (Gold 5+ trips or ৳5L, Silver 3–4, Bronze 1–2), display only, plus NPS as in (a).
   - (c) Points as the portal design draws them (needs an earning and spending rule from you).
