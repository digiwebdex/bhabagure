# Phase 9 — Accounts: chart of accounts, journal, reports (and the books the client already keeps)

**Status (2026-09-17): §2–§7 built; §8 (carrying the books across) waits on the client's export.**

**Why.** The client keeps the company's books in a small accounting service and wants the same Accounts screens inside
Bhabaghure, with the real books carried across. Bhabaghure already posts every booking, payment and invoice into a
balanced double-entry journal — what it never had was a way to *see* that, or to write an adjustment.

## 1. What was already there

| Piece | Where | Note |
|---|---|---|
| Chart of accounts | `accounts` table, `App\Models\Account` | 24 accounts seeded by migration, each named by a constant in the code, with `type` (asset/liability/equity/income/expense) |
| Journal | `journal_entries` + `journal_lines`, `LedgerService` | Append-only; debits must equal credits; a correction is a reversing entry |
| Cash book | `transactions`, Admin → Payments | Money in and out with a receipt, a category and a reversal |
| Invoices | `invoices` (+ items), booking and deal kinds | Issued from a booking, or as a one-line deal invoice |

So the engine was complete and the screens were missing. Nothing about how money is recorded changed in this phase.

## 2. Chart of accounts (built)

- **Two sorts of account.** *Built in* — the 24 the code posts to (`Account::CASH`, `PACKAGE_SALES`, …), flagged
  `is_system` by migration `2026_09_16_210000_accounts_manageable`. They can be reworded, never renumbered, re-kinded or
  deleted. *Staff accounts* — anything else the agency wants to track.
- **Numbers.** A staff account takes the first free number in its kind's range: assets 1500–1999, liabilities 2500–2999,
  equity 3500–3899, income 4500–4999, expenses 5500–5999 (`Account::RANGES`). Below those ranges are the software's own.
  A number may be typed instead; it must be free and inside the range.
- **Deleting** is refused for a built-in account, or one with any journal line — archive it instead.
- **Balances** are shown the account's own way round: assets and expenses rise on debits, liabilities, income and equity
  on credits, so a positive figure always means "more of what this account is for" (`AccountBooks::balance`).
- API: `GET/POST/PUT/DELETE /admin/accounts` (`accounts.view`, `accounts.manage`). Screen: Accounting → Chart of accounts.

## 3. Journal entries (built)

- **The list** is every posting: bookings, invoices, payments, opening balances, and staff entries. Filters: kind
  (staff or software), dates, account, description.
- **A staff entry** needs a date, a description and at least two sides that balance to the paisa. The form says
  *Balanced* or *Out of balance by …* and refuses to post until it does.
- **Money accounts are refused.** Cash, bank, bKash, Nagad, Rocket and the SSLCommerz clearing account move only through
  the cash book, so the books keep matching the receipts and the gateway statements.
- **Corrections are reversals.** A staff entry can be reversed once, with a reason; both entries stay. Entries the
  software wrote are corrected where they were made (void the invoice, reverse the payment).
- Only `journal.post` may write — the accountant and the Super Admin. Audit: `journal.posted`, `journal.reversed`.
- API: `GET /admin/journal-entries`, `POST /admin/journal-entries`, `POST /admin/journal-entries/{id}/reverse`.

## 4. Reports (built)

- **Account transactions** — one account, opening balance carried from before the period, every entry in order with a
  running balance, and the closing figure.
- **General ledger** — every account's debits, credits and balance for the period, grouped by kind, with the totals that
  must agree (a badge says whether they do).
- Both download as CSV (`?format=csv`, UTF-8 with a byte-order mark so Excel reads Bangla names).
- API: `GET /admin/reports/account-transactions`, `GET /admin/reports/general-ledger`.

## 5. Invoice builder (built)

An invoice staff write themselves, beside the ones a booking issues.

- **The list** — tabs for all, unpaid, partly paid, paid, overdue and drafts, each with its count; filters by date and a
  search over the number, the customer and the title; the period's invoiced and outstanding totals.
- **The builder** — who it is for (a customer found by name or number, or a name and number that make the record), as
  many lines as it needs with a per-line discount and VAT rate, a discount on the whole invoice, a due date, a note and
  the words printed at the foot.
- **Draft, then issued.** A draft has no number, is not in the books and can be changed freely. Issuing gives it the
  next invoice number, posts receivable, sales and VAT to the journal, and freezes the figures — a mistake is voided and
  written again. Paying and voiding stay on the deal endpoints, where the ledger already handles them.
- **The printed invoice** says a line's own discount and VAT under it (the amount is the line after its discount, so it
  would not otherwise read as quantity × rate), the due date, and the staff footer above the standing terms.
- **Every invoice belongs to someone.** The invoices table insists on a customer or a B2B client, so what is owed can
  always be traced; a name and number typed into the builder find or make that customer record.
- **Payment** on a row takes money against the invoice through the same endpoint the Payments screen uses — one payment,
  one cash book entry, one ledger posting — with the receipt required as everywhere money is recorded by hand. The
  method and the account are asked for separately: cash into the office drawer and cash into a staff member's float are
  the same method and different money. It needs `transactions.create_manual`, and a booking's invoice is paid on its
  booking, where it belongs.
- **Send reminder** is written by the staff member and sent by the system, by SMS or email, through the same pipeline as
  every other message — so the Notifications log shows whether it arrived. The SMS composer says how many parts it costs
  before it goes. It needs `notifications.send`.
- **Share invoice** hands over the customer's own link, to copy or to pass to WhatsApp on the staff member's own device.
- **Printed four ways** from the row: A4, A5, an 80mm counter slip, and a delivery receipt — the last with no prices and
  a line to sign for the documents handed over.
- **Details** reads the invoice back the way the customer's copy reads, with every payment against it.
- **Delete** throws away a draft. An issued invoice is never deleted — the customer has a copy and the books have the
  entry — so that one is voided on its deal, which reverses the journal.
- API: `GET/POST /admin/invoices`, `GET/PUT /admin/invoices/{id}`, `POST /admin/invoices/{id}/issue`,
  `DELETE /admin/invoices/{id}`, `POST /admin/invoices/{id}/reminders`. Reading needs `payments.view`, writing
  `invoices.manage`, sending a reminder `notifications.send`.

**Matched to the books the client already keeps.** The screen follows their old system: the state bar across the top,
the customer/from/to/invoice-ID filters, the number with who wrote it and who last touched it, the customer, the date,
the total, what is owed with how late it is, the state, and then Payment · Send reminder with everything else behind
one button. Three of their options are deliberately not here: **Return** (there is no refund or credit-note workflow —
a staff-recorded payment is corrected by reversing it), the **row checkboxes** (nothing acts on a selection), and the
**SMS credit** figure (we do not hold a credit balance; the composer says the part count instead).

**The printed page (2026-09-17).** The client sent their own invoice and asked for it, so the print view was rebuilt to
match: the letterhead with the Civil Aviation number, the barcode over the invoice number, INVOICE set large at the
right, the two dates in a blue band, `Sl. · Item · Qty · Price · Total` under a blue header, the total in figures with
the amount **in words** beside it, each payment as its own line with its reference, Amount Due, Total received and the
payment breakdown, Notes / Terms, and two signature lines above the thank-you. Three deliberate differences from
theirs: the amount in words is written out in words (theirs prints the digits again), the page counter is left off
(theirs prints "Page 0 OF 0"), and amounts keep the ৳ symbol, which is what every customer-facing document here uses.

This is the shared print view, so booking invoices and quotations changed with it — one look for everything the company
prints. It replaces the layout from `_design/Bhabaghure Invoice.dc.html`. A4 reserves the exact height a pre-printed pad
needs whether the header is printed or not, so nothing below it moves; A5 is always printed whole, so there the
letterhead takes only the room it needs.

## 6. Where money sits, and moving it (built)

Whether an account holds money is a property of the account (`accounts.is_money`), not a fixed list of six codes in the
source. The company keeps cash in named floats — Riad, Jannat and Ashik each carry one — as well as in the office
drawer and the bank, and each has to be visible on its own inside the company balance. Only an asset can hold money,
and the answer is settled before an account's first entry: changing it afterwards would move the company balance under
everyone's feet.

With that comes the transfer the books were missing: cash banked, a float handed over, a wallet emptied into the bank.
Nothing comes in or goes out, so it is one journal entry and never a cash book row, and no account can hand over money
it does not hold. `POST /admin/transfers`, needing `transactions.create_manual`.

A payment can also name the account it landed in rather than taking the one its method implies — cash into the office
drawer and cash into a staff member's float are the same method and different money.

## 7. The Accounting screens, as the client already works (built)

The client sent their own Chart Of Account and Transactions screens and asked for the same. Both were rebuilt to match.

**Chart Of Account.** One kind at a time across a gradient bar — Assets · Liabilities & credit cards · Income ·
Expenses · Equity — and under each, the sections accounts are actually looked for in: Cash and Bank, Money in Transit,
Expected Payments from Customers, Operating Expense, Cost of Goods Sold, Business Owner Contribution and the rest
(`App\Support\Ledger\AccountGroups`, 33 in all). A section that holds nothing says so rather than disappearing. Each
row shows the balance in brackets, the account number, when it was last used, its description, and edit and delete.
Adding starts from the section's own button, so the new account lands where it was asked for. The number and
description sit behind "Edit account ID and description", as they do on theirs.

Every account the software posts to already has its section — SSLCommerz clearing is **Money in Transit**, which is
exactly what it is: taken from the customer, not yet settled. A section belongs to one kind, so a liability can never
be filed under Operating Expense.

**Transactions** replaced the Payments screen: one cash book, in one place. The account picker carries every balance
(the company balance lives there now), then Cash in · Cash out · Transfer balance · More, the period filter, and the
table — date with its reference and a Cash in/out badge and who recorded it, description, account, category, amount
and its receipt. The deals card went with it: standalone invoices are the Invoices screen's job now. The SSLCommerz
review queue moved across and shows only when something needs checking.

Three things theirs has that ours will not:

- **Edit** and **Delete** on a posted entry. The cash book is append-only, in the database as well as the code, and
  that is what makes every figure above it worth reading. A mistake is corrected with a reversing entry, and the row
  says so where the Edit would have been.
- Because of that, the **approval tick** is a record of its own (`transaction_approvals`), not a column on the entry:
  ticking one off changes not a single column of it. It needs `transactions.approve`, which the admin holds and the
  accountant does not — whoever records money should not be the one who says it has been checked.
- **VAT payment** is new here, not copied: money out of a chosen account against VAT payable, with its receipt. Without
  it the VAT owed figure only ever grows.

An entry now records **which account** the money sat in (`transactions.money_account_id`), not just the method it came
by, so the office drawer and a staff member's float are told apart. Entries written before that keep a null and fall
back to the account their method has always meant; they are not back-filled, because the table refuses an update.

## 8. Carrying the books across (next)

**Decided with the client (2026-09-16):** import everything — accounts and balances, customers, invoices and cash
transactions — because both systems are the same company, and clear Bhabaghure's demo data first (one test customer and
its website inquiry; the accounting tables are empty). Old invoice numbers are kept as the customers received them.
Vendors and purchases arrive as money only (no purchase or stock screens). Cash floats held by named staff count as
company money, inside the company balance, like the office cash and the bank.

**How.** An artisan command reads the exported books at a path given when it runs and writes them into Bhabaghure. The
repository is public, so no exported data is ever committed; the file stays on the server for the run.

**Limit found on 2026-09-16:** the service protects its login with a reCAPTCHA, so signing in from a script is not
possible and nothing is scraped. The books come from an export the client provides, or from their own signed-in browser.
