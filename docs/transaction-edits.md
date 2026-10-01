# Edit and Delete on the cash book (2026-10-01)

**Asked:** Edit Transaction and Delete Transaction in the Actions menu of the Transactions table. Edit opens a form for
the date, account, category, description, reference and amount. Editing or deleting recalculates the bank/cash
account balances and the ledger. Only super admin or an authorised admin may do it, and every edit and delete is kept
in the audit log.

**Decided (the client's answers, 2026-10-01):**
- **History is kept.** Nothing is overwritten (the cash book stays append-only, docs/phase-9-accounts.md §7):
  - **Delete** cancels the entry with a reversing entry;
  - **Edit** does the same, then records the corrected entry.
  The list shows the book as it stands. **Show edits and deletions** shows what was there before.
- **All staff-recorded entries:** manual income and expenses, and customer payments staff recorded on a booking or an
  invoice. Online (SSLCommerz) payments are not changed here: the gateway confirmed them, and they are refunded through
  it.
- **A new permission,** `transactions.edit` ("Edit and delete transactions"), held by super admin and admin, and
  grantable to another role in Roles. The old **Reverse** became **Delete** and needs it too, on the Transactions screen
  and on a booking's payments: accountants record entries but no longer remove them unless the permission is granted.
- **Its own month:** the cancelling entry carries the original's date, so the month an entry belongs to shows the
  corrected figure. The history and the audit log still show what it was before.

## How it works

**Transactions → a row's Actions (⌄):**
- **Edit** opens a form with the entry's date, account, category, description, reference and amount, and an optional
  reason. Saving:
  - cancels the entry on its own date;
  - records the corrected one, keeping the same receipt and the same booking or invoice;
  - shows the corrected entry in the list with "Edited by …" and what changed (for example "Amount: BDT 35,000 →
    BDT 30,000").
  A customer payment keeps its category ("customer payment") and can't be raised above what is owed — the entry's own
  amount counted back in. The method follows the account: unchanged when the account is, otherwise the one that
  account means.
- **Delete** asks for a reason. The entry is cancelled on its own date and leaves the list. With "Show edits and
  deletions" it reads "Deleted by …: reason", beside the entry that cancels it.
- **Unavailable**, greyed out with the reason shown on hover:
  - an online payment, or its charge and fee lines;
  - a VAT payment;
  - an entry that cancels another;
  - an entry already edited or deleted;
  - without the permission.
  **Edit** alone is also unavailable, but **Delete** still works, for:
  - a salary paid from Payroll, or a bonus paid out: deleting it makes the month or withdrawal unpaid again (as reversing
    always did), to be paid afresh there;
  - a payment sent with a bKash charge: deleted, then recorded again on the booking, where the charge is worked out.

**A booking's payments:** **Delete** (formerly Reverse) works the same way, with the same permission.

**What follows on its own:** every figure is worked out from the entries, so all of these update with no separate
recalculation:
- the money accounts and the company balance;
- a booking's or an invoice's paid amount, amount due and status;
- the journal and the reports.
The corrected entry is a new one, so an approval tick on the old one does not carry over: it needs checking again.

## Data and API

- `transaction_corrections` (append-only, LedgerTables): `kind` edit · delete, `original_transaction_id`,
  `reversal_transaction_id`, `replacement_transaction_id` (edits), `changes` `{ field: [before, after] }`, `reason`,
  `staff_id`.
- `LedgerService::reversePayment(…, $on)` dates the reversal, in the cash book and the journal. `recordPayment(…, $into)`
  can land a booking payment in a named money account, as deal payments and manual entries already could.
- `App\Services\Ledger\CashBookCorrections`: `edit()`, `delete()`, `editBlocked()`, `deleteBlocked()`.
- `POST admin/cash-book/{id}/edit` (`occurred_on`, `account`, `category`, `business_line`, `description`, `reference`,
  `amount`, `reason`) answers with the corrected entry. `POST admin/cash-book/{id}/reverse` (`reason`) is Delete;
  `POST admin/transactions/{id}/reverse` is Delete from the booking page. Neither adds a route that updates or deletes a
  ledger row (LedgerImmutabilityTest).
- `GET admin/cash-book` hides cancelled entries and their reversals unless `history=1`. Rows carry
  `actions.edit` / `actions.delete`, `edit_blocked` / `delete_blocked`, `edited` (on a corrected entry) and `correction`
  (on an edited or deleted one).
- Audit: `cash.edited` (what changed, the reason, the new entry) and `cash.deleted` (the reason, the entry as it was).

## Tests

- `api/tests/Feature/CashBookCorrectionsTest`:
  - an edit's dates, journal, balances, list, history and audit;
  - an invoice payment edited (amount due follows; over it refused, nothing changed);
  - online and VAT entries refused.
- `api/tests/Feature/AdminBookingTest`: a booking payment edited into another account, the booking and invoice
  following, and deleted from the booking page.
- `api/tests/Feature/PayrollTest`: a salary paid from Payroll can't be edited in the cash book, only deleted.
- `PaymentsBooksTest`: accountants can no longer delete; the list without and with the history.
- `admin/e2e/transactions.spec.ts`: record, check, edit, see the history, delete; the balance follows each time.
- `admin/e2e/payroll.spec.ts`: Edit disabled on a salary payment, Delete making the month unpaid again.
