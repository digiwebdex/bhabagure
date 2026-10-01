# Quotations: custom packages, and the internal note vs the customer's (2026-10-02)

**Asked (client):**
1. The package is no longer required. Staff can quote a trip that isn't one of the website's packages, with its own
   title and details.
2. An internal note that never reaches the customer: not on the PDF, the customer's link, the portal, or the WhatsApp
   or email messages. A separate note *for* the customer.

**Found:** the admin labelled the one note field "Internal note — for the office only, not printed or sent", but the
API printed it on the quotation. Every PDF, the attachment on the "quotation sent" WhatsApp and email, and the
customer's link carried it. So staff writing costs or margins there were showing them to customers. Live had two
quotations with a note, one of them sent.

**Decided (the client's answers):**
1. **A custom package is priced from its own lines**, each at a price per person. New booking's ★ Custom service works
   the same way. The quote multiplies each line by the travellers, then takes off the discount and adds service charge
   and VAT.
2. **It converts to a custom service booking**, with the quoted lines and price.
3. **Existing notes became the internal note**, because they were written as private. The customer note starts empty.
4. **The custom details show on the PDF and in the customer's portal**, under the title.

## How it works

**Admin → Quotations → New quotation / a draft:**
- **Package:** choose a package, or **★ Custom package (not on the website)**. A custom package shows:
  - Package title;
  - Package details (itinerary, hotels, what's included), printed under the title;
  - its lines, each with a price per person, plus "+ Add item".
- A custom package has no room choice, hotel category or add-ons. Anything like that is a line of its own. The travel
  date is optional, as for any quotation.
- **Note for the customer:** printed on the quotation and shown in their portal. Use it for what's included or how to
  pay.
- **Internal note · staff only** (dashed amber box): costs, margins, reminders. It shows on the quotation's page in the
  admin, marked "only staff see this", and nowhere else.

**Where each note goes:**

| | Note for the customer | Internal note |
|---|---|---|
| Admin quotation page and editor | yes | yes, marked staff only |
| PDF (download, WhatsApp and email attachment) | yes | **never** |
| The customer's link (`/public/quotations/{token}`) | yes | **never** |
| Portal → the quotation | yes ("A note from us") | **never** |
| WhatsApp / email message text | no (the message carries no notes) | **never** |

## Data and API

- Migration `2026_10_02_100000`:
  - `quotations.is_custom` and `package_details`, for a custom quotation;
  - `internal_note`;
  - the existing `notes` moved into `internal_note`.
- `Quotation::$hidden` includes `internal_note`, so a serialized quotation never carries it. The admin resource
  (`AdminQuotation`) names it field by field.
- `POST/PUT admin/quotations`:
  - takes `package_slug` **or** `custom: {title, details, items: [{title, unit_price}]}`, never both and never neither;
  - takes `notes` (the customer's) and `internal_note`.
- `QuotationService::priceCustom` prices like `BookingCreator::customQuote`. The editor does the same with
  `invoiceTotals`, and the save is checked against the total shown (`price_changed`).
- `BookingCreator::createFromQuotation` carries `is_custom` across, so a custom quotation books as a custom service.
- `QuotationView::TEMPLATE_VERSION` 4: the details print under the title. The bump also makes every cached quotation
  PDF render again, so no cached file keeps an old note.
- Portal `GET portal/quotations/{number}`: `details` and `note`, never the internal note.

## Tests

- `api/tests/Feature/QuotationTest`:
  - a custom quotation: priced from its lines; neither, both or no lines refused; details printed with no room line;
    a revision keeps it; it converts to a custom service booking;
  - the internal note absent from the print view, the PDF's HTML, the customer's link, the portal, every notification
    and a serialized quotation; the customer's note present.
- `admin/e2e/quotations.spec.ts`:
  - a custom package priced in the editor (BDT 30,600 for four);
  - saved, then reopened with its title and both notes;
  - sent: the internal note shown to staff only, and the customer's link with the trip and their note but not the
    internal note.
