# Phase 9 — Accounts: chart of accounts, journal, reports (and the books the client already keeps)

**Status (2026-09-16): §2–§4 built; §5 (invoice builder) and §6 (import) next.**

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

## 5. Invoice builder (next)

Today a deal invoice is one line with a title and a total. The plan: multiple lines with per-line discount and VAT,
saved as a draft and then issued, and the extra print sizes (A5, POS slip, delivery receipt) beside the A4 invoice.

## 6. Carrying the books across (next)

**Decided with the client (2026-09-16):** import everything — accounts and balances, customers, invoices and cash
transactions — because both systems are the same company, and clear Bhabaghure's demo data first (one test customer and
its website inquiry; the accounting tables are empty). Old invoice numbers are kept as the customers received them.
Vendors and purchases arrive as money only (no purchase or stock screens). Cash floats held by named staff count as
company money, inside the company balance, like the office cash and the bank.

**How.** An artisan command reads the exported books at a path given when it runs and writes them into Bhabaghure. The
repository is public, so no exported data is ever committed; the file stays on the server for the run.

**Limit found on 2026-09-16:** the service protects its login with a reCAPTCHA, so signing in from a script is not
possible and nothing is scraped. The books come from an export the client provides, or from their own signed-in browser.
