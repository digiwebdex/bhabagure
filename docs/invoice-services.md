# Invoice lines for tickets and hotels: Air ticketing, Hotel requests, Departure soon, WhatsApp (2026-11-04)

**Asked:** on a new invoice, say what a line is for — Air Ticket, Hotel Booking or Tour Package — with the fields that go
with it; a ticket then shows in Air ticketing and a hotel booking in Hotel requests, with the status changeable from
either side. Flag a departure that is close. Record a schedule change and tell the customer by WhatsApp in one click.

**Decided (the client's answers, 2026-11-04):**
- The choice is **on each line**, so one invoice can sell a flight and a hotel.
- Sold tickets and bookings are a **new tab beside the enquiries**, which keep their own 24-hour badge and Claim / Mark
  quoted flow. Tickets (Bookings) opens first.
- A line reaches the lists **when the invoice is issued** ("Save invoice"); a draft doesn't.
- **Departure soon** shows from **7 days** before, red at 3 or fewer.

## 1. On the invoice

Under each line, **This line is for** (the invoice's own title box is already labelled "What it is for"):

| Choice | Fields | Needed |
|---|---|---|
| Other | none: every line before this, and anything else sold | |
| Air Ticket | From (starts as Dhaka), To, Trip type, PNR, Departure, Return, Ticket status, Passenger | From, To, Trip type, Departure; Return on a round trip |
| Hotel Booking | Hotel, Room type, Reservation / confirmation no., Check-in, Check-out, Booking status, Guest | Hotel, Check-in, Check-out |
| Tour Package | Package name (starts as the item), Departure, Return | nothing |

Picking a package in the Item box makes a plain line a Tour Package. From, To and the passenger or guest weren't in the
brief's field list; the lists and the WhatsApp message need the route, and a passenger may not be the person billed
(blank = the customer). A PNR can come later. **Existing invoices are untouched**: their lines are Other, and opening one
and correcting it can say what a line was for, which redoes its books as any correction does, at the same figures.

**Invoice details** shows each line's details and status, with **Update status** while it is issued.

**The printed invoice and PDF** (A4, A5, slip, delivery) print under the line, in English like the rest:
`PNR AB12CD · Dhaka → Bangkok · Round trip · Departure 8 November 2026 · Return 15 November 2026`, or the hotel's
`Hotel Sea View · Deluxe twin · Reservation RES-778 · Check-in … · Check-out …`. As they stand today: a PNR or a new date
reaches the next copy (the stored PDF's key includes them). **The status and the schedule note are not printed.**

## 2. Air ticketing → Tickets, Hotel requests → Bookings

`/air-ticketing` and `/hotel-requests` open on what invoices sold; the other tab is the website's enquiries, at
`?state=open`, so the sidebar badge (`?state=open&stale=1`) still opens exactly the rows it counts.

- **Columns:** passenger or guest (and the customer if different), phone, route or hotel, dates, PNR or reservation,
  status, invoice number (a link). Never a price.
- **Filters:** Upcoming (the next departure first; the default), Departure soon and Schedule changed (Air only, each with
  its count), Past, Cancelled, All. Search by name, phone, PNR or reservation, route, hotel or invoice number.
- **Departure soon** (Air only): from today to 7 days ahead, by the date in Dhaka: "Departure soon · 5 days left", red at
  3 or fewer, "· today" on the day. Not on a cancelled invoice or once the date has passed.
- **Update status** (the row, or its ✎): Booked, Confirmed, and for a flight Schedule changed, with the PNR and dates. Schedule
  changed needs a **New schedule** note; choosing another status clears it. Every change is in the audit log with before and after.
- **A correction** of the invoice updates the same record by its key (`line_key`) and keeps a Schedule changed status
  and note. A line taken off, or changed to Other, removes its record (`service_record.removed` keeps what it was).
- **A cancelled invoice** moves its record to the Cancelled filter, marked so, and it can no longer be changed.

## 3. The WhatsApp button

Opens the staff member's own WhatsApp (`wa.me`) with the message ready to send: **nothing is sent by the system and it is
not logged**, as with the other contact buttons. It goes to the number on the invoice, in the customer's language
(Bangla when not known; `AdminServiceRecord::whatsAppText`, words in `api/lang/*/services.php`).

- **Schedule changed:** "The schedule of your flight has changed." with PNR, route, the new schedule and the dates.
- **Otherwise, a flight:** the flight's details, status, and a reminder to keep passport and tickets ready.
- **A hotel:** the hotel, room, reservation number, dates and status.

Each ends with the office's number and the company's name.

## 4. Data and access

- `service_records` (migration `2026_11_04_100000`): `invoice_id`, `line_key` (unique), `type`, `passenger_name`,
  `reference` (PNR, in capitals, or reservation number), `trip_type`, `route_from`, `route_to`, `starts_on`, `ends_on`,
  `hotel_name`, `room_type`, `package_name`, `tour_package_id`, `status`, `schedule_note`, `schedule_changed_at`,
  `updated_by_staff_id`. `invoice_items.line_key` ties a line to its record: an issued invoice's lines are frozen and a
  correction rewrites them, but these details change after issue, so they live beside the lines.
- Written with the lines by `InvoiceBuilder`, drafts included; the lists show only issued invoices (`sold()`), which is what
  "when it is issued" means. No copy is made, so the invoice and the lists can't disagree.
- `lines[].service` on `POST` / `PUT admin/invoices`. `GET admin/service-records?kind=air|hotel&filter=&search=&page=`
  needs the queue's `.view` permission. `PUT admin/service-records/{id}` needs its `.manage`, or `invoices.manage`, since the
  invoice changes the same record. A tour package has no list.

## 5. Checked

- API: `ServiceRecordTest` (a draft sends nothing, each line to its own list, what each kind needs, a correction keeping the
  record and status, a line removed, a cancelled invoice, 7 and 3 days by Dhaka's date, the schedule note and both
  languages' messages, search, who may see and change, the printed lines on A4 and slip).
- Admin: `admin/e2e/invoice-services.spec.ts` (the form's refusals, a ticket and a hotel on one invoice, both lists, the
  badge, a schedule change reaching the WhatsApp link, the tabs, the same record updated from the invoice);
  `air-ticketing`, `hotel-requests` and `invoices` specs still pass.
