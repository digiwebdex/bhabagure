# Phase 5 — Admin core: shell, Dashboard, Bookings, Customers, Quotations, Payments

**Status (2026-09-14): approved, being built.** Three small date defects found on the way were fixed during planning
(§10, marked *fixed*).

## 0. Decisions (2026-09-14)

**Answers to §12**
1. **Ownership.** A booking belongs to the staff member who created it; an admin can reassign it. Website bookings,
   website leads and air-ticket enquiries have no creator: they sit in a **shared pool** every sales agent sees, and a
   **claim** makes the claimant the owner. Claims and reassignments are written to the audit trail, because commission
   follows the booking — it is money.
2. **Quotations:** staff-side lifecycle (§12 option a).
3. **Payments in the books:** full double entry — money account + category for manual entries, one audited opening
   balance per money account, deals as standalone invoices (§12 option a).
4. **Badges:** Bookings (inquiries you can see), Quotations (sent, expiring within 48 h), and **Air ticketing**
   (enquiries not marked quoted, older than 24 h — the rows the queue flags).

**Added to Phase 5 by the design re-sync**
- **Air ticketing, queue only** (§4.7). The website's air-ticket enquiries become visible. Row actions: WhatsApp and
  email only (no SMS, consistent with the SMS rule) and **Mark as quoted**. No "Quote" button: an air quote is a
  different shape and gets its own type with the rest of air ticketing (PNRs, fares, commission) later.
- **My commission, read-only half** (§4.8): own sales, commission earned and pending. The withdrawal form shows
  "coming soon" until the Phase 7 bonus ledger exists. Company balance, other staff's commission and profit figures
  answer **403** to roles without the permission — enforced in the API, not by hiding buttons.
- Staff documents (Vault) stay with the Vault module; when built: private storage, signed-in access, encrypted
  numbers, and a staff member reads only their own documents unless they manage staff.

**Money formatting (global)**
- "৳" in every interface in both languages: `৳ 1,50,000` (en) / `৳ ১,৫০,০০০` (bn) — website, admin, invoice PDF,
  email, WhatsApp.
- Short forms: `৳ 14.2L` / `৳ 2.4Cr` (en), `৳ ১৪.২ লাখ` / `৳ ২.৪ কোটি` (bn). One decimal, as the design's own code
  computes it (`(amount / 100000).toFixed(1) + 'L'`), lakh from 1,00,000 and crore from 1,00,00,000, deciding the
  unit after rounding (99,96,000 → `৳ 1.0Cr`, never `৳ 100.0L`). Below one lakh the full amount.
- **SMS is the one exception:** `BDT` in both languages (`BDT 1,53,000` / `BDT ১,৫৩,০০০`), because "৳" is not in the
  GSM-7 alphabet and would make every English SMS Unicode (70 characters a part instead of 160). The switch lives in
  the SMS channel only.
- Money is stored and sent as numbers and formatted when shown. The message log keeps the exact text that was sent
  (a record). Typed-in visa fees in package excludes, blog copy and the FAQ move to a setting when that copy is next
  touched.

**Kept from the plan, open to veto:** the seven-icon column on Bookings, Customers and Quotations keeps ✉ SMS as the
device's own `sms:` link (unlogged, costs the company nothing), as §3.3 describes; the air-ticket queue has no SMS
button, as decided above.

**How this plan was made.** Nine read-only investigators worked in parallel:
- `_design/README.md` §2 and the rest of the README;
- the shell and badges;
- Dashboard, Bookings, Customers, Quotations and Payments in `Bhabaghure Admin.dc.html`;
- the backend and data model;
- a browser measurement of the prototype in desktop Chrome at 1440, 1024 and 390 px, light and dark, at rest, hovered and selected, at 0/25/50/75/100 % scroll.

A completeness critic then compared their findings. Three adversarial verifiers rechecked the 24 claims that most affect decisions: 23 were confirmed, and 1 had a wrong line number with the claim itself holding. Measurements and screenshots are kept outside the repository. The local `_design` copy is the 2026-09-13 08:13 one; the re-sync you mentioned only changes the pricing-rule defaults, which are already built.

---

## 1. Scope

**In Phase 5:**
- the admin shell (navigation, derived badges, page header, search, "+ New booking");
- Dashboard;
- Bookings (list rebuilt on the shared row-actions table; the Phase 3 detail page stays);
- Customers & leads;
- Quotations;
- Payments & invoices;
- the defects in §10.

**Not in Phase 5** (the other §2 screens, each with the module that needs it): Corporate, B2B agent portal, Flights, Hotels, Visa tracker, Departure calendar, Itinerary builder, Manifest, Ops checklist, Suppliers, Accounting, Refunds, Reports, Inbox, Campaigns, Attendance & salary, Staff & bonus, System, Document vault & tasks, Roles & audit, Settings & import. Also out: the customer portal (Phase 6) and the wallet. Nothing is linked in the sidebar before it exists.

## 2. What the design says, and how each finding is handled

| # | Finding (source) | Handling |
|---|---|---|
| 1 | Five sidebar badges are constants: Bookings 2, Quotations 2, B2B 1, Visa 2, Attendance 2 (Admin.dc.html L1575–1585). Measured: each one contradicted its screen as soon as a status changed. Nearby counters also disagree with their lists (Quotes "12 open" vs 5 rows). | Every badge comes from the same filtered, permission-scoped query as its list (§3.1). |
| 2 | The seven-icon action cell is sticky only on Bookings (L122, `background: inherit`). Six other tables use a fixed `var(--sf)` background (L196, L214, L298, L712, L752, L784). | One shared table component for every list with row actions (§3.2). |
| 3 | The Dashboard is literal numbers that contradict other screens: "New leads 11" vs 3 new leads; a 17 Sep departure listed on 22 Sep; "Monday 22 Sep 2026", which is a Tuesday. README lists "recent bookings", which the prototype lacks. | Every widget is computed (§4.2). Recent bookings is added, because the README asks for it. |
| 4 | Money in Bangla mode uses Latin digits; "৳ 14.2L" is stored as a string; many labels are English only. | Everything goes through `@bhabaghure/format` and i18n. A compact-lakh formatter is added with its PHP twin and fixtures. |
| 5 | The Bookings side panel sets any status directly. | Status changes only through the Phase 3 state machine. |
| 6 | Quote maths ignores the group slab, single supplement and add-ons; VAT is a flat 2 %; USD is converted at a literal 120. | Quotations price with `@bhabaghure/pricing` / `PricingService`, the same as bookings. |
| 7 | ✕ on ledger rows deletes a cash-book entry, which the append-only books forbid. | ✕ becomes "Reverse…" with a reason, where reversal is allowed (§3.3). |
| 8 | The Payments balance is a hardcoded ৳12,40,000 opening plus invoice amounts counted as cash. Deal receipts never reach the ledger. The manual cash form has no money account, so it can't post a journal entry. | Balances come from the journal; manual entries need an account (question 3). |
| 9 | The drawer breaks at 900 px in the prototype (L1715) and 1024 px in the README. | 1024 px, as built. |
| 10 | Customers and Ledger tables have no header row; Quotations' → convert button sits outside the sticky cell and can't be reached at 1024 px until fully scrolled. | Header rows everywhere; convert lives in the actions cell. |
| 11 | README says "33 screens in 8 groups"; the prototype has 30 items in Dashboard + 7 groups. | Design group order, showing only built screens (§4.1). |

## 3. The two requirements, built in from the start

### 3.1 Derived badges

- **One endpoint:** `GET /api/v1/admin/nav-counts` returns `{ key: { count, filter } }` for the badges the staff member may see, and leaves the rest out.
  - A server registry (`App\Support\Admin\NavBadges`) maps each key to its permission and a **filter array**.
  - The list endpoint accepts that same filter array, and both run the same model scope — for example `Booking::visibleTo($staff)`, which replaces the visibility rule copied in three controllers today.
- **The rule a badge can't break:** `nav-counts[key].count === GET <list>?<filter>.meta.total` for every seeded role (super admin, admin, accountant, tour operator, and a sales agent with their own and someone else's records). A contract test per badge checks it, and repeats it after each mutation that can change the count (confirm, cancel, convert, pay, claim, delete).
- **Clicking a badge** opens the list with its filter in the URL, so the list shows exactly the badge's number. List filters move into the URL for that reason.
- **Freshness:** one TanStack query `['nav-counts']`. It is invalidated by a global `MutationCache` hook after every mutation, polled every 60 s and refetched on window focus, because online payments, website leads and quote expiry change counts without a staff click. Zero shows no pill.
- **Which badges, and what they count:** question 4.

### 3.2 The sticky row-actions cell

The design gives the pattern (`position: sticky; right: 0`, `align-self: stretch`, a background that inherits the row's) but no list of the four defects you saw. The investigators found **eight failure modes**. Four are what breaks if one part of the pattern is missing; four are defects the measurements found even in the prototype's own Bookings table. The build covers all eight. If the four you saw aren't among them, tell me and I'll add them to the test.

| # | Failure | Build rule |
|---|---|---|
| F1 | Cell not sticky: icons scroll out of reach | `sticky right-0` on the actions `<td>` and its `<th>`, inside the card that scrolls horizontally; no overflow, transform or contain between them |
| F2 | See-through cell: amounts show through the icons | The cell is `bg-inherit` from a row that always paints an opaque token; never an alpha colour on a row or cell |
| F3 | Cell only as tall as its icons: two-line cells peek out above and below | A real `<table>`: the cell is full row height, with padding on cells, not rows; the icon group is centred inside it |
| F4 | Fixed background: a white block on a hovered or selected row | The row owns the colour (`bg-app-surface`, `hover:` and `aria-selected:` tints); the cell inherits it, in light and dark |
| F5 | Rows end at the visible width: tint, divider and click target stop short when scrolled (measured: row box 694 of 842 px at 1024) | `w-full min-w-max`; borders on cells with `border-separate border-spacing-0`, not on `<tr>` |
| F6 | Column 2 px narrower than its icons: ✕ clipped (192 px of icons in a 190 px column) | Width comes from the content; no icon ever clipped at any scroll position |
| F7 | No right gutter at full scroll: icons touch the card edge | The gutter is the cell's own right padding |
| F8 | Nothing marks the sticky edge: pills cut mid-word with no hint the table scrolls; header label misaligned with the icons | A 1 px edge (or soft shadow) while content is hidden under the cell; header label aligned with the icon group; `z-1` on the cell |

Also built in:
- **Unavailable actions:** always a real disabled button with the reason as a tooltip, so the column keeps its width.
- **Accessible names and focus:** each action's name is "WhatsApp — BH-2609-041"; focus rings sit inside the cell; `scroll-padding-right` stops keyboard focus from hiding under the cell.
- **Rows:** a row click never swallows the icons' own clicks.

**Test:** a Playwright spec checks all eight on the real admin tables. It covers 1024 and 390 px, light and dark, at rest, hovered and selected, at 0/50/100 % scroll. It uses computed styles, bounding boxes, `elementFromPoint` hit-tests, and pixel samples under and beside the cell.

**Phone width (deviation, open to veto).** At 390 px the scroll area is 360 px, and a 210 px sticky column covers more than half of it: the prototype hides 39 text runs under the cell at scroll 0. Below 640 px the cell stays sticky but shows one "⋯" button that opens the same seven actions in a menu. At 640 px and wider, all seven icons show.

### 3.3 What the seven icons do

| Icon | Bookings | Customers & leads | Quotations | Ledger rows |
|---|---|---|---|---|
| ◉ View | open the booking page | open the customer profile | open the quotation | open the linked booking or entry |
| ✎ Edit | booking page (draft quote) | customer form | quotation editor (a revision once sent) | disabled: entries are never edited |
| ✆ WhatsApp | `wa.me` in the staff member's own WhatsApp (Phase 4 decision, unlogged); the logged send stays on the booking page | same | same, with the quote reference | the linked customer, if any |
| ✉ SMS | the device's `sms:` link, unlogged. System SMS stays a fallback only, never a per-row send | same | same | same |
| @ Email | `mailto:` | same | same | same |
| ⎙ PDF | invoice PDF, fetched with the staff token | disabled | quotation PDF | receipt / evidence |
| ✕ | soft delete with confirmation, only with no invoice and no payment; otherwise disabled with the reason (cancel instead) | delete only with no bookings or quotations | withdraw a sent quote; delete a draft | "Reverse…" with a reason, only where reversal is allowed (never online payments or already-reversed rows) |

Without a phone or email the action is disabled with the reason.

## 4. Screens

### 4.1 Shell

- **Sidebar, in the design's group order, built screens only:**
  - Dashboard (no group heading)
  - বিক্রয় / Sales: Bookings (badge) · Quotations (badge) · Customers & leads · Packages · Pricing & seats
  - হিসাব / Finance: Payments & invoices
  - যোগাযোগ / Communication: Notifications · Website: Blog · Team · Reviews · Gallery · Media · Site settings

  The Services, Operations, HR and System groups appear with their modules. Every item is permission-filtered, as it is today.
- **Home:** `/` becomes the Dashboard for everyone. Its widgets are filtered by permission (§4.2).
- **Header:** a bilingual title and subtitle per screen, with today's date as Dhaka weekday and date. Search opens grouped results from bookings, customers and quotations: `GET /admin/search?q=`, top five of each, visibility-scoped. "+ New booking" opens a staff booking form, shown only with `bookings.create`.
- **Kept from Phase 2:** the drawer below 1024 px with Escape and scrim, the language and theme toggles, and the role label.

### 4.2 Dashboard

Every number is computed in Dhaka time, so there are no literal values.

| Widget | Definition | Who sees it |
|---|---|---|
| **আদায় · Collected** (this month) | Cash received in the month, net of reversals — the same figure as the Payments method cards. Its sub-line shows invoiced sales, so both meanings of "revenue" are visible and labelled. | `payments.view` |
| Bookings (this month) | Bookings confirmed this month, with the change against the same elapsed days last month; "awaiting payment" as a sub-line | everyone, visibility-scoped |
| Departures | Group departures in the next 30 days; sub-line: the next date | everyone |
| New leads | Leads with no contact yet; sub-line: how many are older than 24 h | `customers.view`, scoped |
| Seats left | Free seats across those departures (confirmed pax and active holds) | everyone |
| Passport details missing | Travellers on upcoming confirmed trips with no passport number — the same rule as the Phase 4 "documents pending" message | bookings, scoped |
| Alerts | Each item links to its record: passports missing for trips within 7 days; leads unanswered over 24 h; online payments needing review; failed notifications in the last 24 h; WhatsApp not connected | each alert behind its permission |
| Upcoming departures | Up to five, with seat bars; link to the full list | everyone |
| Collected by destination | This month, grouped by destination (custom trips as "Other") | `payments.view` |
| Recent bookings | The last eight, on the shared row-actions table | bookings, scoped |

### 4.3 Bookings

- **List** on the shared table. Status chips carry their counts from the same scoped query as the badge. Search, status, payment status and page live in the URL.
- **Columns:** Reference, Customer (name and phone), Package (title, dates, travellers), Total, Due, Status (booking and payment), Actions.
- **Ownership:** unassigned website bookings, and claiming them, depend on question 1.
- **Row click and ◉** open the existing booking page. The prototype's side panel isn't built: the Phase 3 page (draft quote, invoice preview, payments, travellers, messages) doesn't fit in a panel. This is a deliberate deviation.
- **Staff booking form** ("+ New booking"): customer (search, or new lead), package and departure (or a custom trip), travellers, room and add-ons. It prices with `@bhabaghure/pricing`, checks and holds seats, assigns the creator, and sends the Phase 4 messages. It uses a new `StaffBookingCreator`, which quotation conversion also uses.

### 4.4 Customers & leads

- **Lead board:** New · Contacted · Quoted · Converted, plus a Lost filter. Cards show name, a source pill, interest, age, and next step (overdue in amber).
- **Customer list** on the shared table: name, phone, a passport status chip (on file / expiring / missing — never digits, masked or not), trips (completed bookings, next trip shown separately), assigned staff, actions.
- **Profile:** contact details, WhatsApp opt-out, a contact log (call, WhatsApp, Facebook, visit, email, with outcome and next follow-up), quotations, bookings.
- **New lead form:** source (walk-in, phone, Facebook, WhatsApp, referral, website); phone required.
- **Duplicate check:** by phone against the unique active customer.
- **Loyalty tiers and phone-less Facebook leads** are deferred: the design disagrees with itself on the first (trips or spend in the admin, points in the portal).
- **The lead model and who owns it** is question 1.

### 4.5 Quotations

- **KPIs**, all from the same scoped query:
  - open quotes and their total;
  - conversion rate over the last 90 days;
  - expiring within 48 h (the badge, question 4);
  - average value.
- **Table** on the shared row-actions component: number, customer (package and travellers), value, valid until, status, actions (convert is inside the actions cell).
- **Editor:**
  - Pick a customer record, never free text: Phase 4 looks numbers up server-side.
  - Choose a package and departure, or a custom trip. Set travellers, room, add-ons and discount. VAT uses the existing rates.
  - Validity is 3, 7 or 14 days.
  - Totals update live from `@bhabaghure/pricing`.
  - The save is checked by the PHP twin with `expected_total`, like the booking draft quote.
- **Numbering:** `QT-0001`, one running counter. A revision after sending gets a new number that points back to the original.
- **Frozen price:** lines and pricing settings are copied into `quotation_lines`, and the price is honoured until `valid_until`. Expiry is derived from `valid_until` in Dhaka time, never stored twice.
- **PDF:** the invoice renderer with the same letterhead and header-off setting; passport numbers never appear.
- **Sending:** a new Phase 4 event `quote_sent`, by WhatsApp with the PDF, and by email. Never SMS.
- **Convert** creates an inquiry booking from the frozen lines through `StaffBookingCreator`. It is idempotent, so a quote converts once. It keeps the link both ways, and the booking then follows the normal Phase 3 path.
- **Named "Quotation"**, because "quote" already means a booking's draft pricing on the booking page.
- **How much of the lifecycle to build** is question 2.

### 4.6 Payments & invoices

- **Company balance:** the journal balance of the money accounts. Cash, bank, mobile wallets and SSLCommerz clearing are shown separately, so money not yet settled is visible.
- **Method cards** for this Dhaka month: bKash, Nagad, SSLCommerz (card, bKash or Nagad through the gateway), and cash or bank. Each shows the amount net of reversals and the number of payments.
- **Cash book** on the shared table, read-only:
  - Columns: date, description, booking or party, method, reference, recorded by, in/out, and an evidence link.
  - Filters: direction, method, category, date range.
  - Reversed entries are marked. ✕ is "Reverse…".
- **Online payments needing review:** SSLCommerz attempts that settled below the expected amount, carried a surcharge, or were flagged risky. They are listed with a "Mark reviewed" action, which adds `reviewed_at` and `reviewed_by`.
- **Manual cash in / out, opening balance and deals:** question 3.
- **Saved references:** preset labels for the reference field (e.g. "Office rent", "Pokhara Grande advance"), editable by staff with `transactions.create_manual`.
- **Evidence uploads:** receipts, bank slips or bKash screenshots as images or PDF. They go to the private disk and are served only through an authenticated route, never the public media pipeline.

### 4.7 Air ticketing — enquiry queue only

- **Source:** the website's air-ticket form already stores `inquiries` rows of type `air_quote` (from, to, dates,
  passengers, cabin class, contact). Nothing else about air ticketing is built.
- **Queue:** open enquiries first, oldest first; rows older than 24 h (Dhaka time, not marked quoted) flagged, with the
  age shown ("26 h" / "২৬ ঘণ্টা"). Filters: open · quoted · all, in the URL.
- **Row actions** on the shared table: ◉ view details, ✆ WhatsApp and @ email to the number and address on the enquiry,
  **Mark as quoted** (records who and when, audited; can be undone by the same person or an admin). No SMS, no Quote.
- **Ownership:** unclaimed enquiries are in the shared pool; claiming works as for bookings (§0).
- **Badge:** open, unquoted enquiries older than 24 h that the staff member can see — the same query as the flagged rows.
- **Permission:** new `air_inquiries.view` / `air_inquiries.manage` (admin, sales agent).
- **Layout:** from the re-synced design once it can be read here; built after the rest of Phase 5.

### 4.8 My commission — read-only half

- Own sales, commission earned and commission pending, for the signed-in staff member only; the route takes no staff
  id, so no parameter can point it at someone else.
- Withdrawal form: shown disabled with "coming soon" until the Phase 7 bonus ledger.
- Company balance, other staff's commission and profit: `ledger.view_company_balance` / `commission.view_all` checked
  in the API; a sales agent calling those endpoints directly gets 403 (tested per endpoint).
- **Commission rules and layout:** from the re-synced design once it can be read here; built after the rest of Phase 5.

## 5. Data model

| Change | Purpose |
|---|---|
| `quotations` | Number, `revision_of_id`, customer, package or custom trip, departure, snapshot fields (title, duration, airfare), travellers, room, list and unit price, amounts (subtotal, supplement, add-ons, discount, VAT rate and amount, total), `valid_until`, `status` (draft · sent · accepted · declined · withdrawn · converted; expiry derived), `sent_at`, `accepted_at`, `converted_booking_id`, created and assigned staff, soft deletes |
| `quotation_lines` | Same shape as `booking_lines`, so conversion copies rows one to one |
| `bookings.quotation_id` | The reverse link |
| `customer_contacts` | Contact log: customer, staff, channel, outcome, note, `next_follow_up_at`, occurred at (append-only) |
| `customers.lost_at`, `lost_reason`, `interest` | Lead state that can't be derived (question 1) |
| `payment_attempts.reviewed_at`, `reviewed_by_staff_id` | Clearing the review queue |
| `transactions.evidence_path` | Exists; now used |
| `reference_presets` | Label, direction, sort |
| Chart of accounts additions | Expense, other-income and equity accounts for manual entries and opening balances (question 3) |
| `document_sequences` key `quotation` | QT numbering |

The booking reference counter changes to one running counter (§10). All money stays `DECIMAL(12,2)`, and ledger tables stay append-only.

## 6. API

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/nav-counts` | signed-in staff; keys filtered by permission |
| GET | `/admin/dashboard` | signed-in staff; widgets filtered by permission |
| GET | `/admin/search?q=` | scoped per entity |
| POST | `/admin/bookings` | `bookings.create` |
| DELETE | `/admin/bookings/{id}` | `bookings.delete`; no invoice and no payment |
| POST | `/admin/bookings/{id}/claim`, `/assign` | question 1 |
| GET, POST, PUT | `/admin/customers`, `/admin/customers/{id}`, `/admin/customers/{id}/contacts` | `customers.view` / `customers.manage` |
| GET, POST, PUT | `/admin/quotations`, `/admin/quotations/{id}` | new `quotations.*` (§7) |
| POST | `/admin/quotations/{id}/preview`, `/send`, `/accept`, `/decline`, `/withdraw`, `/revise`, `/convert` | `quotations.manage` / `quotations.convert` |
| GET | `/admin/quotations/{id}/pdf`, `/admin/quotations/summary` | `quotations.view_*` |
| GET | `/admin/cash-book`, `/admin/payments/summary` | `payments.view` (balance figures: `ledger.view_company_balance`) |
| POST | `/admin/cash-entries` (manual in/out with evidence) | `transactions.create_manual` |
| POST | `/admin/payment-attempts/{id}/review` | `payments.view` |
| GET, POST, PUT, DELETE | `/admin/reference-presets` | `transactions.create_manual` |

The route test that refuses any PUT, PATCH or DELETE on ledger paths stays in force.

## 7. Permissions

- **New:** `quotations.view_all`, `quotations.view_own`, `quotations.manage`, `quotations.convert`.
- **Now used for the first time:** `payments.view`, `ledger.view_company_balance`, `bookings.create`, `bookings.delete`, `customers.view` (so far only checked inside the notifications controller).
- **Roles:**

  | Role | Quotations | Payments |
  |---|---|---|
  | Admin | all | all |
  | Sales agent | own | none |
  | Accountant | view | view, company balance, manual entries |
  | Tour operator | none | none |

- **Deploy step:** the seeder deliberately leaves existing roles' permissions alone in production, for a Roles screen that isn't built yet. Adding permissions there needs an explicit, audited command (`php artisan permissions:sync --add-only`) that grants new permissions without taking any away. It goes into the deploy doc.

## 8. Formatting (`@bhabaghure/format` + PHP `Numerals`, fixture-held)

- **`formatBdt`:** "৳" in both languages (was "BDT " in English); SMS passes `{ currency: 'code' }` for "BDT" (§0).
- **`formatBdtCompact`:** "৳ 14.2L" / "৳ ১৪.২ লাখ", "৳ 2.4Cr" / "৳ ২.৪ কোটি" (§0).
- **`formatWeekdayDate`:** "Tuesday, 22 September 2026" / "মঙ্গলবার, ২২ সেপ্টেম্বর ২০২৬".
- **`formatDateRange`:** "12–16 Oct 2026".
- **`formatRelativeAge`:** "26 h" / "২৬ ঘণ্টা", "3 days".

Admin dates are always Dhaka calendar dates (§10).

## 9. Deliberate deviations from the prototype

1. The Bookings side panel isn't built; row click opens the existing booking page (§4.3).
2. Below 640 px the actions collapse into a "⋯" menu (§3.2).
3. Status changes only through the state machine; ✕ never deletes money.
4. Quote pricing uses the real slab, supplement, add-ons and VAT.
5. The Customers list shows a passport status chip, not a masked number.
6. Dashboard "Revenue" is labelled "Collected", with invoiced sales beside it.
7. No sidebar item for a screen that doesn't exist yet.
8. Status colours follow the prototype: Confirmed blue, Completed green (the admin shows the reverse today).

## 10. Defects in the existing code, found during the investigation

| Defect | Evidence | Fix |
|---|---|---|
| Nothing assigns website bookings, customers or inquiries to anyone, so a sales agent (`bookings.view_own`) sees none of them | No production code writes `bookings.assigned_staff_id`; `BookingController` limits own-view staff to assigned or created rows | Question 1 |
| The booking reference counter restarts every month (`BH-2609-001`); the approved schema and the prototype run one counter (`BH-2608-036 → BH-2609-037`) | `DocumentNumbers::bookingReference` vs `phase-1-schema.md` §3.7 | Phase 5: one running counter (nothing is deployed, dev data isn't real) |
| *Fixed:* the admin showed an ISO timestamp on its UTC date, the wrong day for Dhaka times between 00:00 and 06:00 | `useFormat().date()` sliced the string | Uses the Dhaka date |
| *Fixed:* the Record payment dialog defaulted to, and capped at, the UTC date, and the API rejected "today" as a future date between 00:00 and 06:00 Dhaka | `BookingDetailPage` `toISOString().slice(0,10)`; `before_or_equal:now` on a UTC-midnight date | Dhaka date on both sides; test at 02:00 Dhaka |
| *Fixed:* a backdated staff payment was journaled with today's date, while its cash-book row had the payment date | `LedgerService::post` used `now()` | The journal entry takes the payment's Dhaka date; test |
| `customers.stage` never becomes `customer`, and source values disagree (`website_form` in the docs, `website_booking` and `website` in code) | `BookingCreator`, public booking controller | Phase 5: set on the first confirmed booking; one source vocabulary |
| The paid-amount drift check promised in Phase 3 isn't scheduled | `routes/console.php` | Phase 5: a nightly check with an admin alert |
| `inquiries.assigned_staff_id` isn't fillable | `Inquiry` model | Phase 5 |

## 11. Tests

- **API:**
  - badge-equals-list contract per role and per mutation;
  - dashboard definitions against a fixture dataset in Dhaka time, including month boundaries at 00:00–06:00;
  - quotation pricing against the PHP twin fixtures, freeze honoured until expiry, convert idempotent, revision numbering;
  - staff booking creation (seats, assignment, messages);
  - manual cash entries balanced, reversal only;
  - evidence served only when authenticated;
  - visibility per role on every list, count and search.
- **Admin e2e:**
  - the sticky-actions matrix (§3.2) on Bookings, Customers, Quotations and Cash book;
  - clicking each badge lands on a list of the same size, and the count changes after the related action;
  - create lead → quotation → send → convert → booking → record payment → Collected updates;
  - Bangla and English, light and dark.
- **Formatting:** new fixtures in both twins.

## 12. Questions (answered 2026-09-14 — see §0)

1. **Leads and ownership.** What is a lead, and who owns website bookings and leads, so that sales agents see anything?
   - **(a) Recommended.**
     - A lead is a customer record with `stage = lead`. Website inquiries attach to it by phone, creating one if needed.
     - Stages New / Contacted / Quoted / Converted are **derived** from the contact log, quotations and bookings. Only Lost (with a reason) is stored. This matches the Phase 4 rule that a stranger needs a lead record first.
     - Unassigned website bookings and leads form a **shared pool** every sales agent sees, with an audited "Claim". Admins can reassign. Anything staff create is assigned to the creator.
   - (b) Same model, but only admins see unassigned records and assign them; agents see nothing until assigned.
   - (c) Keep leads as extended `inquiries` rows until conversion, with automatic round-robin assignment.
2. **How much of the quotation lifecycle?**
   - **(a) Recommended: staff-side.**
     - Create and revise, with the price frozen until expiry.
     - PDF.
     - Send by WhatsApp with the PDF, and by email.
     - Staff mark accepted or declined.
     - Convert to booking.
     - "Viewed" and an expiry reminder wait for the Phase 6 customer portal. Until then they aren't shown at all, rather than faked.
   - (b) Full: also a private customer quote page on the website that records Viewed and lets the customer accept, plus an automatic reminder before expiry.
   - (c) Minimal: create, PDF and convert; no sending from the system.
3. **Payments in the books.** What does a manual cash entry record, where does the company balance start, and what is a deal?
   - **(a) Recommended.**
     - A manual cash in/out requires a money account (cash, bank, bKash, Nagad) and a category that maps to new chart accounts: expenses, other income, owner's capital or drawings. The design's "Source" stays as a business-line tag, and evidence is optional.
     - A one-time, audited opening balance per money account, against an equity account.
     - "Deals · advance & due" become a standalone invoice for a customer or client (no package), paid through the ledger like a booking, with advance and due derived.
   - (b) Manual entries post the other side to a suspense account for the accountant to reclassify. No opening balance: the balance is labelled "since go-live". Deals wait for the Corporate / B2B phase.
4. **Which sidebar badges, counting what?** Whatever you choose, clicking the badge lands on a list of exactly that many.
   - **(a) Recommended: only where the design has badges.**
     - Bookings: inquiries you can see (the Inquiry chip shows the same number).
     - Quotations: sent quotes expiring within 48 hours, Dhaka time (the "Expiring soon" KPI shows the same).
   - (b) As (a), plus Customers & leads (new leads with no contact for 24 h) and Payments (online payments needing review). The design doesn't show these, but both are actionable.
   - (c) As (a), but Bookings counts bookings awaiting payment (unpaid or part-paid, not cancelled) instead of inquiries.

## 13. Build order after the answers

1. **Shared foundation:**
   - visibility scopes for bookings, customers and quotations;
   - the nav-counts registry and its contract test;
   - the shared row-actions table and its eight-failure e2e matrix;
   - the new format helpers with twins;
   - the permissions sync command;
   - the booking reference counter.
2. **Shell:** navigation, badges, header, search, Dashboard route.
3. **Bookings:** list on the shared table, staff booking form (`StaffBookingCreator`), delete and claim.
4. **Customers & leads:** model, contact log, board, list, profile.
5. **Quotations:** tables, pricing and freeze, PDF, `quote_sent` notifications, convert.
6. **Payments:** cash book, summaries, review queue, manual entries, presets, evidence, opening balances and deals as decided.
7. **Dashboard:** built last, on the queries the other screens already prove.
8. **Wrap-up:** full checks, docs as built, deploy notes.

## 14. As built (in progress)

Steps 1–5 of §13 are built; Payments, Dashboard, Air ticketing screen and My commission follow.

**Ownership and the pool (steps 1, 3, 4)**
- The pools are: bookings that are unassigned inquiries; unassigned leads; unassigned open air-ticket enquiries. Quotations have no pool — staff make every one.
- A staff member without `*.view_all` must claim a pool record before working it (sending a message, editing): the API answers 409 `claim_first`, and the row's contact buttons are disabled with that reason.
- Claiming a booking also claims its customer if that customer is still an unowned lead. Picking an unowned lead for a staff booking or a quotation claims it.
- Reassigning (bookings, customers, enquiries, quotations) needs `records.assign` and a reason; claims and reassignments are audited.

**Quotations (step 5)**
- Numbering `QT-0001`, taken when the draft is created (a deleted draft leaves a gap; quotations are not tax documents).
- A draft is priced from current package and add-on prices and checked against the editor's `expected_total` (409 `price_changed` with the new quote). Sending starts validity: `valid_until` = today in Dhaka + 3, 7 or 14 days. From then on nothing re-prices.
- **Expired** (sent, past `valid_until`) and **expiring** (sent, ending within 48 h) are derived in Dhaka time in one model scope each; the list filter, the chip counts, the KPI and the sidebar badge all use them.
- An expired quotation can't be accepted or converted, only declined, withdrawn or revised. An **accepted** one keeps its price after the date: it was accepted in time.
- **Revise** creates a new draft with a new number pointing back, starting from the frozen lines; saving it re-prices. Asking again returns the same open draft. Sending the revision withdraws the original (audited with reason `revised`).
- **Convert** books at the frozen price, copying the lines, as an inquiry booking. The booking belongs to the quotation's owner (commission follows it), `created_by` is whoever converted. Converting twice returns the first booking. Deleting a converted booking (allowed only without invoice or payments) puts the quotation back to accepted.
- **PDF**: the invoice print view with `kind = quotation` — same letterhead and header-off pad setting; validity instead of payments; never travellers or passport data. Public share link `/api/v1/public/quotations/{token}` (and `/pdf`) once sent; drafts have none.
- **`quote_sent`**: WhatsApp with the PDF attached and email with it attached; never SMS, no SMS fallback. Templates are editable on the Notification templates screen. A message still waiting when the quotation is withdrawn or deleted is cancelled (`quotation_withdrawn`).
- A customer with quotations can't be deleted; the lead state **Quoted** means a quotation was sent and not withdrawn.
- Row actions: → Convert, then the seven of §3.3 (✎ is Revise once sent; ✕ is Delete for a draft, Withdraw once sent).

**Deviations (open to veto)**
1. **Custom trips are not quotable yet.** A quotation needs a published package: booking and invoice lines have no "custom trip" kind, and the prototype's form offers only packages. Custom trips come with the Itinerary builder.
2. **No `POST /quotations/{id}/preview`.** The editor prices live with `@bhabaghure/pricing` from `GET /quotations/options`, and the save is the check.
3. **The prototype's "PDF" button in the new-quotation panel is "Save draft".** A PDF needs a saved quotation; the PDF is on every row and on the quotation page (with and without the company header).
4. **The travel date is optional on a quotation** ("date not fixed yet") and required when converting; converting may pick another date, because the price doesn't depend on it.
5. **"Viewed" and "Auto reminder before expiry" are not shown** (question 2: they wait for the customer portal); the table note says how validity works instead.
