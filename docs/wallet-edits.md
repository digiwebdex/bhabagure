# Edit, Delete, View and Add payment in the wallet (2026-10-09)

**Asked (the owner, in Bangla):** after saving, every transaction and any other data in the wallet can be modified,
deleted, added to, and receive new payments; set icons for Edit, View, Delete and Add payment everywhere in the wallet.

**Decided (the owner's answers, 2026-10-09, each as recommended):**
- **History is kept**, as in the company cash book (docs/transaction-edits.md): the list shows the book as it stands,
  and a deleted entry leaves the list and every figure. **Show edits and deletions** shows what was there before.
  Nothing is overwritten or erased.
- **Deleting a deal deletes its money too:** its advance and payments are cancelled with it, so that money leaves the
  balance. The confirmation says how much first.
- **The reason is optional** on Delete and Edit: a box is there, and can be left empty.

## What people see

Icons (drawn in the wallet itself, each with its name as a tooltip and for screen readers):
- 👁 **View**, ✎ **Edit**, 🗑 **Delete** on every row of **Transaction history**, and on each advance and payment under a
  deal. On a phone a history row folds into two lines, so the icons stay in sight.
- 👁 **View**, ✎ **Edit**, ⊕ **Add payment** and 🗑 **Delete** on every deal. Add payment is greyed out once a deal is
  fully paid.
- ✎ **Rename** and 🗑 **Delete** for each business or source (under "Add or edit a business or source") and each saved
  reference (under "Edit saved references").

**View (an entry):** the amount, cash in or out (or the deal's advance or payment), reference, source or deal, date,
when it was recorded, the evidence (opens in a new tab), and its **history**: recorded, each edit with what it changed
("Amount: BDT 1,20,000 → BDT 1,00,000") and the reason, and a delete. A version that was edited says so and opens the
newer one. Edit and Delete are also inside View.

**Edit (an entry):** the fields it was saved with: cash in or out, amount, source, reference, date, and evidence (keep
it, attach a new file instead, or remove it), with an optional reason. A deal's advance or payment keeps its deal: no
cash in/out or source to change, and the amount can't go above what the deal has due with its own amount counted back
in. Saving is only possible once something changed.

**Delete (an entry):** the question, what follows (it leaves the balance and the list; for a deal's money, the deal has
that much due again), and the optional reason.

**View (a deal):** total, received and due; its advance and payments, each with View, Edit and Delete; and its own
history: made (total and advance), then each edit with what changed and why.

**Edit (a deal):** name, total and note, optional reason. The total can't go below what the deal has received. The
deal's money shows under its new name everywhere: history, breakdown and View.

**Add payment:** the amount (what is due, to start with, and no more), the date, a reference (empty means "Due
payment") and evidence.

**Delete (a deal):** "Its advance and payments, BDT … in all, leave your balance too", and the optional reason. The deal
leaves the list and the total due.

**Rename a source:** the new name shows on the entries already made with it and in the breakdown. Deleting one hides it
from the form; entries keep its name, and adding the same name again brings it back. A name a deleted source still
holds can't be given to another one; the message says how to bring it back.

**Rename a saved reference:** only the saved words change; entries already made keep the words they were saved with.

After any change the wallet reloads what is on screen before the dialog closes, so the next click works on current
figures.

## How it works

- **The ledger stays append-only** (`wallet_transactions` is in LedgerTables; no route updates or deletes it,
  LedgerImmutabilityTest):
  - **Delete** adds a reversal: the other direction, same amount, **on the entry's own date** (so the month an entry
    belongs to shows the figure as it now stands), `reverses_id` → the entry, `reason`.
  - **Edit** adds the same reversal, then the corrected entry with `replaces_id` → the entry it corrects. An entry is
    corrected once at most (unique `replaces_id`); the corrected entry can be edited again, making a chain.
  - "Standing" entries (not reversals, not cancelled) are the only ones counted in the balance, totals, breakdown,
    month figure and deals' received and due. An entry already edited or deleted can't be changed again
    (`already_changed`); a reversal can't be changed at all (`not_changeable`); the same values again are refused
    (`nothing_changed`).
  - The old "Reverse" (before 2026-10-09) made the same reversal, so any entry reversed then now reads as deleted.
- **Deals** are not ledger: an edit changes the row in place (audit `deal_edited` with `{ field: [before, after] }` and
  the reason); a delete sets `deleted_at` and `deleted_reason` and cancels its standing money with reversals (audit
  `deal_deleted`). A deleted deal answers 404 to view, edit, pay and delete.
- **Names now:** an entry's source shows its source's (or deal's) current name; the name it was recorded under
  (`source_name`) is the fallback. The breakdown groups by that current name.
- **Evidence:** an edit keeps the file unless a new one replaces it or it is removed. Old files stay with the old
  versions and still open from View.
- **Audit (the wallet's own log):** `entry_edited`, `entry_deleted`, `deal_edited`, `deal_deleted`, `source_renamed`.
- Everything is still the signed-in person's own (docs/personal-wallets.md): another wallet's ids answer 404.

## Data and API

- Migration `database/migrations/wallet/2026_10_09_200000_wallet_edits.php`: `wallet_transactions.replaces_id`
  (nullable, unique, FK to itself); `wallet_deals.deleted_at`, `deleted_reason`.
- `App\Wallet\Services\WalletBook`: `edit()`, `delete()`, `editDeal()`, `deleteDeal()` (the old `reverse()` is gone).
- `App\Wallet\Http\EntryRows`: how an entry reads (status `standing` · `edited` · `deleted`, `changed_at`,
  `change_reason`, `replaced_by_id`, `replaces_id`, `changes`), and `withVersions()` for View.
- Routes, all POST or GET (`routes/wallet.php`):
  - `GET transactions?history=1` (edited and deleted entries too; reversals are never rows), `GET transactions/{id}`
    (with `versions`), `POST transactions/{id}/edit` (multipart: `direction`, `amount`, `source_id`, `reference`,
    `occurred_on`, `evidence`, `remove_evidence`, `reason`), `POST transactions/{id}/delete` (`reason`).
  - `DealController`: `GET deals`, `POST deals`, `GET deals/{id}` (with `history`), `POST deals/{id}/edit` (`name`,
    `total`, `note`, `reason`), `POST deals/{id}/delete` (`reason`; answers `removed`), `POST deals/{id}/payments`.
  - `POST sources/{id}/rename` (`name`), `POST presets/{id}/edit` (`label`); `sources/{id}/archive` and
    `presets/{id}/remove` are the deletes, as before.
- Wallet app: `EntryDialogs.tsx` (View, Edit, Delete, and the icons), `DealDialogs.tsx` (View, Edit, Add payment,
  Delete), `NameList.tsx` (rename and delete in place), `icons.tsx`, `IconButton` and `Dialog` in `ui.tsx`.

## Tests

- API `WalletEditsTest`:
  - an edit's reversal (date, reason) and corrected entry, the list and the history, what changed, evidence kept,
    removed and replaced (old files still open), sources that can and can't be chosen, the chain in View, then a
    delete without a reason;
  - a deal edited (total not below received; same values refused), its money under the new name, a payment added and
    edited (not above due), the advance deleted (due again), then the deal deleted with its money, and everything
    about it answering 404 or `already_changed`;
  - sources renamed (shown on old entries and the breakdown), deleted and brought back, a deleted one's name refused;
    saved references reworded per direction, then removed.
- `WalletBookTest` (Delete instead of Reverse) and `PersonalWalletsTest` (another wallet's entry and deal can't be
  viewed, edited, deleted or paid; their source can't be renamed).
- Wallet e2e `wallet.spec.ts`: a source renamed; a deal edited (total below received shown), a payment added from the
  due, the deal's history in View; an entry edited, its change and reason in View, deleted from View with a reason;
  "Show edits and deletions" brings both versions back without Edit or Delete.
