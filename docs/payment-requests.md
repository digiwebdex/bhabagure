# Payment requests: "I've paid" with a screenshot (2026-10-15)

**Asked (the owner):** once a booking is made, under each bank account on the payment page the customer enters how much
they paid and uploads a screenshot from their bank, and sends it as a payment request. Both banks (Mutual Trust Bank and
BRAC Bank) the same. The request comes into the admin under that booking; staff check the screenshot and approve it, and
the booking shows the payment as cleared.

**Decided (the owner's answers, 2026-10-15, each as recommended):**
- **Approve records the payment and confirms the booking:** it is recorded exactly as "Record payment" records one, with
  the screenshot as its receipt; a booking without an invoice gets it issued; an inquiry is confirmed (commission and trip
  messages follow, as when staff confirm). A part payment leaves the booking Partial.
- **From the booking page and the customer portal.**
- **Both banks and bKash.**
- **Under the booking, plus a Payment requests list with a sidebar badge.**

## What customers see

On How to pay (the booking page from the private link, and a trip in the portal), under each bank account and under bKash:
**I've paid — send payment details** opens a small form:
- **Amount you paid** (the amount due to start with; no more than is due). For bKash: the booking amount, without the
  bKash charge.
- **Date you paid** (not later than today).
- **Transaction or reference number** (optional for a bank; the bKash transaction ID is required).
- **Screenshot or photo of the payment** (JPG, PNG, WebP or PDF, up to 5 MB, required).
- **Send payment request**.

Then "Thank you! We'll check your payment and confirm it here." and the list **Payments you sent**, each with its bank,
date, reference and status: **Waiting for confirmation**, **Payment received** (and the amount due goes down), or **Not
accepted** with staff's reason. In Bangla and English.

## What staff see

- **Sales → Payment requests** (admins and accountants — whoever may record payments, `transactions.create_manual`):
  Waiting (oldest first), Approved, Rejected, All, with search by booking, customer, phone or reference. The sidebar badge
  counts those waiting.
- **On the booking:** a **Payment requests from the customer** card above Payments, waiting ones first.
- Each request: amount, bank (or bKash), date paid, reference, where it was sent from and when, **Open screenshot**,
  **Approve** and **Reject**.
  - **Approve**: the amount actually received (the customer's figure to start with; it can be corrected), and for bKash
    whether the customer also sent the bKash charge (booked as a charge, as on "Record payment"). Then the payment is on
    the booking, the invoice, the cash book and the journal; the screenshot is its receipt.
  - **Reject**: a reason the customer reads.

## Checks

- Only the booking's own customer can send one (its private token, or signed in to the portal); anyone else gets 404.
- A screenshot is always required; the account must be one shown under How to pay; no more than is due; no request on a
  completed or cancelled booking; at most 5 waiting per booking; a reference already sent (waiting or approved) is
  refused.
- A request is decided once (`already_decided`); approving can't take a booking past what it owes, and a reference
  already in the cash book is refused.
- The screenshot is on the private disk, opened only by staff who may record payments, never cached. Sending, approving
  and rejecting are in the audit log.

## Data and API

- `payment_requests`: booking, customer, method (`bank_transfer` · `bkash`), bank name and account number as shown, amount,
  `paid_on`, reference, `evidence_path`, `via` (`booking_page` · `portal`), status, `approved_amount`, `transaction_id`,
  decided by, when and why.
- `App\Services\Payments\PaymentRequests`: `submit()`, `approve()`, `reject()`; refusals `PaymentRequestRefused`
  (`lang/*/payments.php` → `requests`).
- `POST public/bookings/{reference}/payment-requests` (multipart). `GET public/bookings/{reference}` and the portal trip
  carry `paymentRequests`.
- `GET admin/payment-requests?status=&search=`, `GET admin/payment-requests/{id}/file`, `POST …/{id}/approve`
  (`amount`, `bkash_charge`), `POST …/{id}/reject` (`reason`). The admin booking carries `payment_requests` and
  `actions.decide_payment_requests`; nav badge `payment_requests`.
- Website: `features/booking/PaymentRequests.tsx` in `PaymentInstructions`. Admin: `features/payment-requests`.

## Tests

- API `PaymentRequestsTest`: the checks above; approval recording the payment with the screenshot, issuing the invoice
  and confirming the booking; a bKash request from the portal approved with its charge; a rejection the customer reads;
  search. `NavCountsContractTest` covers the badge.
- Admin e2e `payment-requests.spec.ts`: two requests; the accountant finds them from the badge, opens the screenshot,
  approves one (booking confirmed) and rejects the other with a reason.
- Website e2e `live-data.spec.ts` (how to pay): the form under BRAC Bank, a missing screenshot caught, the request sent
  and shown waiting.
