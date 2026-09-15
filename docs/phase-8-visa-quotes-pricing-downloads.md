# Phase 8 — Visa, hotel quotes, hotel-category pricing, gated downloads

**Status (2026-09-15): step A built and deployed (§4.A); steps B–E planned.**

## 0. The client's requests (2026-09-15, summarised from Bangla)

1. **Visa section on the home page:** the countries whose visas are processed, each with its requirements, price and
   other information; customers can download the requirements. **Holiday packages downloadable too:** one click gives
   a PDF with the price, tour plan (itinerary), details and other information.
2. **Downloads only for signed-in customers.** Not signed in → sign in or register first, then the download starts.
   Every download is recorded. The Super Admin sees who downloaded what and when, how many customers downloaded, whether
   they are building a package on the website, and their name, mobile and account. Purpose: follow up people who never
   contacted us.
3. **Customise a package from the start:** pick the hotel category first (3★/basic, 4★, 5★), then the number of
   travellers (1, 2, 4, 6, 10); the price follows both.
4. **Facebook reels** from the page, shown near the bottom of the website in the design, are missing on the live site.
5. **Booking with only a name and a WhatsApp number.** Passport scan, passport number, expiry, date of birth and email
   stay on the form but become optional; staff collect them later.
6. **Hotel and Visa beside Tour package and Flight ticket on the home page.** A hotel quotation request (check-in,
   check-out, destination, hotel category, guests, note) that notifies by WhatsApp, email and inside the admin; admins
   answer from the panel.

## 1. What exists

| Area | Today |
|---|---|
| Reels | (Before step A.) The Gallery section showed CMS items as tiles linking to Facebook and hid itself when the CMS had none. Production had none: the design's four reels lived only in the demo seed. |
| Booking form | (Before step A.) Every traveller's name, passport number, date of birth and expiry were required, and the lead's mobile too. The database already allowed them to be empty. |
| Home search | Two tabs: Tour package, Air ticket. Air requests are `inquiries` of type `air_quote`, worked on Admin → Air ticketing (claim, assign, badge, mark as quoted); replies open the staff member's own WhatsApp. |
| Package price | One per-person price (regular/sale), site-wide group discounts (3+, 4+, 6+, 10+), single-room supplement, add-ons — in `@bhabaghure/pricing` and its PHP twin, used by bookings, quotations and invoices. |
| Visa | Nothing: per-traveller visa status on bookings only. |
| Customer sign-in | One-time code by SMS, WhatsApp as fallback (a modal, no password). Neither channel is live yet, so nobody can sign in until WhatsApp is configured. |
| PDFs | Invoices, quotations and payslips through the Chrome renderer; nothing for packages or visas. |

## 2. Decisions (2026-09-15)

1. **Pricing: a price grid per package.** Staff enter the per-person price for each hotel category (3★, 4★, 5★) ×
   traveller tier (1, 2, 4, 6, 10). A group size between tiers uses the tier below (3 people → the 2-person price).
   The site-wide group discounts no longer apply to a package that has a grid.
2. **Sign-in for downloads: the phone code, as now.** Downloads work once WhatsApp (or SMS) is live.
3. **Visa requirement PDFs are generated from the CMS:** country, visa type, price, processing time, requirements and
   notes, in Bangla and English.
4. **Hotel (and air) replies: a queue with a reply message.** A Hotel requests queue like Air ticketing, with a Reply box
   that sends the customer a WhatsApp from the notifications number and an email, logged; then Mark as quoted. Air
   ticketing gets the same Reply box.

**Defaults, open to veto:**
- **Reels:** the design's four reels are published in the Gallery CMS, and reels play in an embedded Facebook player
  (loaded lazily) instead of linking out. Staff add, reorder and hide reels in Admin → Gallery. The section stays near
  the bottom of the home page.
- **Minimal booking:** only the **lead traveller's name and WhatsApp number** are required. Other travellers' names are
  optional too (an unnamed one is saved as "Traveller 2" and so on, for staff to complete); every other field is
  optional but still checked when filled in. The portal's readiness checklist and the admin's document review ask for
  the missing details, as they already do.
- **Downloads log:** a Downloads screen for the Super Admin (permission `downloads.view`, grantable on the Roles screen):
  customer name, mobile, account link, what was downloaded (package or visa), the hotel category and travellers chosen
  for a package, when, and whether they have a booking or quotation in progress; totals of downloads and distinct
  customers. The customer's profile lists their downloads too.
- **Hotel request alerts** use the existing alert recipients (Admin → Notifications → Alerts, a new "Hotel quotation
  request" alert) by WhatsApp and email, and the sidebar badge on the Hotel requests screen is the in-admin
  notification.

## 3. Build order

| Step | What | Depends on |
|---|---|---|
| A | Reels back; minimal booking | — |
| B | Hotel quotation requests, Hotel and Visa tabs, reply box for hotel and air | — |
| C | Visa services: CMS screen, home section, country details | — |
| D | Hotel-category × traveller price grid: package editor, website customiser, bookings, quotations, invoices | — |
| E | Package and visa PDFs, sign-in-gated downloads, downloads log | C, D; live WhatsApp for real sign-ins |

Each step ships on its own with API tests, e2e tests and a deploy.

## 4. Built

### 4.A Reels back; minimal booking (2026-09-15)

**Reels.**
- **Data.** Migration `2026_09_15_170000_publish_facebook_reels` publishes the Facebook page's four most-watched reels
  (104K, 58K, 17K, 11K views) once, with an audit entry. It is a migration, not the content seeder: deploys re-run the
  seeder, which would put back a reel staff had deleted. It does nothing if any reel already exists.
- **Website.** The gallery shows reels as Facebook's own embedded player (`plugins/video.php`), 260 × 462 px, with
  `loading="lazy"`: Facebook's scripts load only as the section nears the screen. The players sit in one row that
  scrolls sideways on narrow screens, each with its view count and an "Open on Facebook" link. A link to the page
  itself (Site settings → Facebook) closes the section.
- **Photos.** Photo items stay tiles linking to the post and still need an uploaded thumbnail to publish. Reels no
  longer need one.
- **Link check.** A reel must be the video's own link: `/reel/<id>`, `/watch?v=<id>` or `/<page>/videos/<id>`.
  A page or photo link is refused with a message saying so.
- **Where it sits.** Below the FAQ and above About, where the design has it. The ☰ sheet links to it; the desktop row
  never did.
- **Local demo.** `DemoContentSeeder` now only adds the demo's photo tiles beside the real reels.

**Minimal booking.**
- **Required.** On the website only the lead traveller's name and WhatsApp number are required.
  - Every other traveller field is optional and marked "(optional)"; anything typed in is still checked.
  - A traveller card with nothing filled in says "Can be added later".
- **API.** The public booking endpoint accepts the same.
  - An unnamed traveller is saved as "Traveller 2" (Bangla: "যাত্রী ২") and so on.
  - Blank optional fields are stored as empty.
- **Staff.** Admin → Booking → Customer & travellers:
  - each traveller shows what is still needed (passport, date of birth);
  - **Edit details** completes name, WhatsApp/mobile, email, passport number and expiry, and date of birth. This is
    `PUT /admin/booking-travellers/{id}`, with `bookings.update` and the claim-first rule for sales agents.
  - The audit log (`traveller.updated`) names the fields changed, never their values.
  - An issued invoice keeps the details it was issued with.
- **Customer.** The portal's readiness checklist and document uploads ask the customer for what is missing, as before.

**Tests.**
- API:
  - `MinimalBookingTest`: booking with name + WhatsApp; required and checked fields; staff completing a traveller,
    the audit and claim-first.
  - `CmsContentTest`: gallery link rules; the four reels, and a deleted reel staying deleted.
  - `PublicContentContractTest`.
- Web e2e:
  - a booking paid after a cancelled payment is made with only the lead's name and WhatsApp number;
  - the gallery's players, order, phone layout and ☰ link.
- Admin e2e: staff complete "Traveller 2".
- Smoke: the home page has one player per published reel.
- **E2E setup fix.** The e2e run now clears the website build's cached API responses (`web/.next/cache/fetch-cache`)
  after rebuilding its database. Before, a build reused the previous run's copies for up to an hour.
