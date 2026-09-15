# Phase 8 — Visa, hotel quotes, hotel-category pricing, gated downloads

**Status (2026-09-16): steps A–D built and deployed (§4.A–§4.D); step E planned.**

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
| B | Hotel quotation requests, Hotel tab, reply box for hotel and air | — |
| C | Visa services: CMS screen, home section, country details, Visa tab (it lists the CMS's countries) | — |
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

### 4.B Hotel quotation requests; a reply box for hotel and air (2026-09-15)

**Website.** The search panel under the hero has a third tab, *Hotel quotation*. The form asks for:
- location, check-in and check-out (the label counts the nights);
- hotel category: Basic / 3-star, 4-star or 5-star;
- guests, and an optional note;
- name, WhatsApp number and optional email.

It posts to `POST /public/hotel-quotes`, which is rate-limited and has the honeypot, like the air form.
- The request is stored as an `inquiries` row of type `hotel_quote`.
- The number becomes a lead on the Customers board, as every website enquiry does.
- The Visa tab moved to step C: it lists the countries from the visa CMS.

**Alert.** A new *Hotel quotation request* alert (`hotel_quote_alert`) goes by WhatsApp and email.
- Recipients are whoever is on its list (Admin → Notifications → Alerts).
- With nobody on the list, it goes to every active super admin and admin, so a request is never announced to no one.
- The general new-lead alert is not sent for hotel requests.
- Both the alert and the reply have editable templates on the Notifications screen.

**Admin → Hotel requests.**
- Permissions: `hotel_inquiries.view` and `hotel_inquiries.manage`. Admins and sales agents get them, and deploy's
  `permissions:sync` grants them on the live database.
- The screen works like Air ticketing: oldest open first, flagged after 24 hours, a sidebar badge of the flagged ones,
  the pool, claim, assign, *Mark as quoted* and *Back to open*.
- The two screens share one API trait (`WorksQuoteRequests`) and one admin page (`features/requests`).

**Reply (Hotel requests and Air ticketing).**
- `POST /admin/{hotel|air}-inquiries/{id}/reply` sends the customer the reply (event `inquiry_reply`).
  - It goes by WhatsApp from the notifications number to the number on the request, and by email to its address.
  - The template opens with "Dear {name}, a reply to your {request} request:" and then the text staff typed.
  - The typed text never enters the audit log: `inquiry.replied` records only the message ids.
- Replying to an unowned request claims it. *Also mark it quoted* marks it in the same step.
- Needs `<queue>.manage` and `notifications.send`, and uses the same per-staff send limits as a WhatsApp from a booking.
- **Preview.** The dialog previews the WhatsApp exactly: the sender line, then the template filled for this request
  around the typed text.
- **When WhatsApp can't send** (no notifications number published, WhatsApp not connected, or the customer opted out):
  - the dialog says so, and the email still goes;
  - the reply's message log shows each channel's status.
- The row counts the replies sent. The customer's profile lists hotel requests with a link to the queue.

**Tests.**
- API:
  - `HotelQuoteRequestTest`: form, lead, alert and its fallback, validation, honeypot, queue, badge, permissions;
    reply by WhatsApp and email with claim, mark quoted and audit; a reply when WhatsApp can't send; an air reply;
    no reply without `notifications.send`.
  - `NavCountsContractTest` covers the new badge for every role, including a reply claiming a pool request.
  - `AirInquiryQueueTest` updated for the reply action.
- Web e2e: the Hotel tab's validation and nights label, and the stored request.
- Admin e2e: a flagged hotel request answered with a reply that marks it quoted. It checks the preview, the held
  WhatsApp and the email status, the badge, and the reply count in Quoted.
- Smoke: the hotel queue refuses anonymous calls.

### 4.C Visa services (2026-09-15)

**Content.** Admin → Visa services (`cms.manage`) holds one entry per country and visa type. Each entry has:
- the country and visa type in both languages, and an optional two-letter country code;
- the price per person including the service charge; left blank, the website shows "Price on request";
- processing time and stay;
- requirements, one per line;
- notes;
- the page address (`/visa/<slug>`, generated from the country and visa type unless typed).

Entries start as drafts and are ordered like the other CMS lists. Publishing needs a processing time and the
requirements in both languages. Nothing is seeded: which countries, prices and requirements appear is the agency's
content, and the section, tab and menu links stay hidden until one is published. Saves refresh the website through the
new `visas` cache tag.

**API.** Table `visa_services`, model `VisaService`, `Admin/VisaServiceController` (the published-list endpoints under
`/admin/visas`), and `GET /public/visas`. Public requirements come as lists per language, falling back to the other
language when one is empty.

**Website.**
- **Visa section.** On the home page after Departures: one card per country, each visa type with its price and
  processing time, a link to its page, and *Ask about this visa* on WhatsApp.
- **`/visa/<slug>` page.** Price, processing time, stay, the requirements as a numbered list (Bengali numerals in
  Bangla), notes, WhatsApp, and links to the country's other visa types. It is listed in the sitemap.
- **Visa tab.** In the search panel, shown once a visa is published: pick a country to see its visa types.
- **Links.** The ☰ sheet and the footer link to the section.
- Country codes show as a small badge, not a flag emoji, because Windows browsers draw flag emoji as bare letters.
- The requirements PDF and the sign-in-gated download come in step E, from the same fields.

**Tests.**
- API:
  - `VisaServicesTest`: publish rules, slug and code rules, price on request, the public shape and language fallback,
    the cache tag, the audit trail, and permissions.
  - `CmsPermissionsTest` covers the new screen.
  - `PublicContentContractTest`: nothing is published by default.
- Admin e2e: add, refused with the reasons, completed and published, then seen on the public API.
- Web e2e: nothing shows before publishing; after publishing, the card, the Visa tab, the English and Bangla pages,
  the requirements list and the sitemap. The menu-links test covers the visa links.
- Smoke: `GET /public/visas`; once one is published, its page and the home section.

**Deploy note (found on the first deploy of step C).** `deploy.sh` builds the website before it migrates and reloads the
API, so the build reads the previous release's API, which had no `/public/visas` yet. The website's content loader now
renders a list the API doesn't serve yet (HTTP 404) as empty. Only lists marked this way do; every other failure still
stops the build. The content refresh at the end of the deploy then fills it in. Any public list added later should be
loaded the same way.

### 4.D Hotel-category × traveller price grid (2026-09-16)

**Decided before building (2026-09-16, all the recommended options):**
1. **Single rooms.** The 1-traveller grid price already includes a single room. For 2 or more travellers the grid is per
   person sharing, and a single room adds the single-room supplement %, as before.
2. **Card price.** A grid package's card shows basic/3-star (or the first category it sells) for the traveller count in
   the search panel, so it follows the stepper like every other card.
3. **No sale price** on grid packages: staff change the grid for an offer.
4. **Existing packages** keep today's price and group discounts until staff fill in a grid.

**Pricing (`@bhabaghure/pricing` and `PricingService`).**
- A grid is `{"3": {"1": …, "2": …, "4": …, "6": …, "10": …}, "4": {…}, "5": {…}}`, holding per-person prices for
  basic/3-star, 4-star and 5-star.
- A category is sold once it has the 1-traveller price.
- A group between two sizes pays the smaller size's price (3 → the 2-traveller price); 10 or more pay the 10-traveller
  price.
- With a grid, `quoteBooking` needs one of the sold categories. The slab it reports is the tier, with no discount.
- The single supplement follows decision 1.
- Helpers: `gridCategories`, `gridRate`, `defaultHotelCategory` and `packagePerPerson`.
- `fixtures.json` has `grid`, `gridRate` and `quoteBookingGrid` cases, and both twins pass them.

**API.**
- `tour_packages.price_grid`.
- `bookings` and `quotations` keep `hotel_category` and that category's row (`price_grid`) as it was priced, the way
  `list_price` is kept. A later change of travellers on a draft invoice uses those prices.
- **Package editor.** It tidies the grid: unknown categories or sizes, and blank cells, are dropped. Each sold row needs
  the 1-traveller price, and prices run 1 to 9,99,99,999. With a grid, the package's regular price becomes the default
  category's 2-traveller price, so older screens (the admin list, the assistant, structured data) stay sensible; the
  sale price is cleared.
- **Bookings and quotations.** Website bookings, office bookings and quotations take `hotel_category`. A grid package
  without a sold category is refused as a validation error on `hotel_category`.
- **Lines.** The package line is titled "… · 4-star hotel" / "… · ৪ তারকা হোটেল", so invoices, quotations, the portal
  and PDFs name the category. Converting a quotation keeps its category and prices.

**Website.**
- **Cards.** Priced from the grid, with "per person · Basic / 3-star · N travellers".
- **Package modal.** Hotel category chips first (only the categories sold), then the group-size chips priced from that
  category.
- **Booking.** Starts in the category chosen in the modal. Step 1 has a Hotel category select, the review line names the
  category, and the payment sends `hotel_category`.
- The contact form's package list and the budget filter use the same card price.

**Admin.**
- **Package editor.** A *Price by hotel category* table; while it holds a sold category, the regular and sale price
  fields are disabled. The summary says which categories are sold and what cards show.
- **New booking and the quotation editor** have a Hotel category select, priced live.
- **Booking page.** Shows the category, and the draft invoice re-prices with the booked grid.

**Tests.**
- Pricing unit tests (14) and `PricingServiceTest` (the shared fixtures).
- API `PriceGridTest`:
  - the editor tidying and rules;
  - the category required and sold;
  - tier pricing without a slab;
  - the snapshot surviving a package price change on the draft invoice;
  - the single-room rule;
  - quotation to booking.
- Admin e2e: the grid refused without a 1-traveller price, then saved; a 3-traveller office booking at 3-star
  (৳ 55,080), then 4-star (৳ 76,500) with the category kept.
- Web e2e: the card caption and price, the modal's category chips and tier prices, and a booking from the modal whose
  total reaches the SSLCommerz stand-in exactly (৳ 1,34,640).
