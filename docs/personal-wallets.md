# Personal wallets

**Asked (2026-10-09):** wallet.bhabaghure.com.bd becomes a personal wallet. Only the admin decides who may use it;
everyone given access keeps their own separate cash record there, integrated with nothing.

**Decided with the client (2026-10-09), each as recommended:**
1. **Only the super admin** gives or takes away a wallet.
2. **Nobody sees anyone else's wallet,** the super admin included: the super admin sees who has one, never what is in it.
3. **Staff only:** a wallet goes to an existing staff account, which signs in with its staff email and password.
4. **From anywhere:** the office IP allow-list is gone, so wallets work at home and on phones. The password and rate
   limits guard it.

**Changed later on 2026-10-09 (the owner: "disable the authentication" → "শুধু ফোনের কোড বাদ, পাসওয়ার্ড থাকবে"):** the
authenticator code is gone. The staff email and password alone open a wallet; there is no phone setup, no code step and
no "Reset authenticator". The code (`Totp`, the `Authenticator` model, `auth/code`, `members/{id}/reset-authenticator`,
`wallet:reset-authenticator`) was removed; the old `wallet_authenticators` table is left in place, unread.

## What people do

- **The super admin** has an **Access** tab beside **My wallet**. It lists active staff; for each:
  - **Give a wallet** / **Take it away** (with a confirmation). Taking it away signs the person out at once; what they
    recorded stays in the database, unread, and is there again if the wallet is given back.
- **Everyone with a wallet** signs in at wallet.bhabaghure.com.bd with their staff email and password, and then has the
  design's screen for themselves alone: balance, cash in and out with
  sources, references and evidence, deals with advances and dues, the breakdown and the history. A new wallet starts
  empty with two sources, Personal and Other.

## How it works

- **Still fully separate from the company** (docs/phase-7-hr-attendance-bonus-wallet.md §8): its own database and
  MySQL user, its own code, keys, sessions and audit log. Nothing here is read by the company system, and nothing in the
  company's books is read here.
- **Access:** table `wallet_members` (staff id, who gave it and when, who took it and when). `WalletAuth::allowed()`: an
  active staff account that is the super admin or holds a standing membership; checked at every request, so a taken-away
  wallet's session ends at once. Routes `GET|POST /wallet/members` and `POST /wallet/members/{id}/revoke`, the super
  admin only (403 for anyone else).
- **One book per person:** entries and deals belong to whoever made them (`created_by_staff_id`: only a wallet's owner
  writes to it, so the append-only ledger needed no new column), and sources and saved references gained
  `owner_staff_id`. Every read and write is limited to the signed-in owner; another wallet's entry, evidence, deal or
  source answers 404, whoever asks.
- **Migration** (`database/migrations/wallet/2026_10_09_100000_personal_wallets.php`): the two built-in sources nobody
  had used were removed (each wallet makes its own Personal and Other); live had no entries yet.
- **The front door:** nginx no longer includes `/etc/nginx/bhabaghure-wallet/allow*.conf` for the wallet host (those
  files can be deleted). Sign-in is limited to 6 a minute and 40 an hour per address, and 10 a minute and 60 a day per
  email. `POST /wallet/auth/password` sets the session cookie directly.
- **Screens:** `wallet/src/Access.tsx`; `WalletScreen.tsx` shows the tabs to the super admin only; the sign-in page is
  the password form alone.

## Tests

- API `PersonalWalletsTest`: only staff given a wallet sign in, and only the super admin decides; taking it away ends the
  session; giving it back opens it again; each wallet's sources, entries, deals, saved references,
  evidence and figures are its owner's alone, and another's ids answer 404, for the super admin too. The existing wallet
  tests (book, sign-in, isolation, accounts) still pass.
- Wallet e2e (`wallet.spec.ts`): the super admin gives a staff member a wallet; they sign in with their own password and
  see an empty wallet without Access; taken away, their session closes. (On 2026-10-09 this PC ran out of memory during
  the browser run; the change was verified on live instead.)
