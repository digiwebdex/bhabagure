# Phase 7 — HR: staff, attendance, salary, bonus; the super admin wallet

**Status (2026-09-15): plan.** Nothing in this phase is built yet. It is waiting on two things: approval of the attendance
agent design (§5.2) and the questions in §11.

**Sources:**
- `_design/Bhabaghure Admin.dc.html`, from the local 2026-09-13 copy. Screens used:
  - HR → Attendance & salary, and Staff & bonus;
  - System → Vault & tasks, and Roles & audit.
- `_design/Super Admin Wallet.dc.html`.
- **The re-synced design.** It adds My commission and Staff documents screens but still can't be read here. Steps 1 and 4
  get a layout check against it, and step 4's commission rules exist only there.
- The brief of 2026-09-15.
- Phase 1 §8 item 10 (wallet isolation) and Phase 5 §4.8 (My commission).
- The code as it stands.

## 1. Order

| Step | Needs first | Waiting on |
|---|---|---|
| 1. Staff records, roles, staff documents | — | Nothing. The layout is re-checked once the re-synced design can be read. |
| 2. Biometric attendance: agent, API, screens | 1, because device users map to staff | Approval of the agent design (§5.2). |
| 3. Salary from attendance | 1 and 2 | — |
| 4. Bonus accounts, My commission | 1 | The commission rules in the re-synced design. Only auto-credit and My commission need them; the ledger, manual credit and withdrawals don't. |
| 5. Super admin wallet | Nothing in the company app | Your go-ahead for the second MySQL user. Also a DNS record, certificate and nginx site for its subdomain, which write outside /var/www/Bhabagure and are asked about at the time. |

## 2. What exists today

| Area | State |
|---|---|
| Staff | The `staff` table has employee code, name, email, phone, status (`invited` / `active` / `suspended`), a forced password change, locale and one role. Only `staff:super-admin` creates anyone. No screen or command adds, invites, suspends or re-roles other staff, and there are no HR fields. |
| Roles | Five system roles and a seeded matrix. `permissions:sync --add-only` already skips permissions a Roles screen revoked (it looks for `role.permission_revoked`). `staff.manage`, `bonus.manage`, `commission.view_own` / `view_all` and `system.roles_manage` exist but guard nothing yet. |
| Passport protection (the model for staff documents) | The number is in an encrypted column with a lookup hash. Files are encrypted on the private disk and served inline with `no-store`, only to people allowed to see the booking. Uploads, reviews and status changes are audited. |
| Append-only rules | `bonus_transactions` and `wallet_transactions` are already listed in `LedgerTables`, so the model guard and the query guard apply as soon as those tables exist. |
| Company books | Double-entry. A Salaries expense account (5210) and a manual `salaries` cash-out category exist. There is nothing for staff bonuses. |
| Commission | Follows the booking owner through the Ownership service; claims and reassignments are audited. Nothing computes or stores it. |
| Attendance, salary, wallet | Nothing. |

## 3. The design, and what is wrong with it

| # | Finding | Proposed handling |
|---|---|---|
| 1 | The device card says the server pulls punches every 15 minutes. The server can't reach a device behind the office router. | An agent on an office PC pulls from the device and pushes to the API (§5.2). "Sync now" and "Test link" reach the device through the agent within a minute. |
| 2 | "Working days 26", with no weekly off days or holidays. Nothing can tell an absence from a Friday. | The rules gain weekly off days (default Friday) and a holiday list. Absent means a working day with no punch and no approved leave. |
| 3 | The pay formula is base ÷ 26 × (full + leave + rate × (late + early)). In a month with 27 working days, it pays the full base to someone absent for one of them. | The same rule written as deductions: base − day rate × (absent days + (100 % − rate) × late-or-early days). It gives the design's figures whenever a month has exactly the configured days (Nabila Karim: ৳ 30,154 either way), and a full month always pays exactly the base. |
| 4 | No rule for a day with a single punch, or for late in *and* early out on the same day. | A single punch counts as a late-or-early day until an admin corrects it; that is configurable (late-or-early, absent or full). Late in and early out on one day is one late-or-early day, because the rule text says "or". |
| 5 | Leave requests are listed, but nobody can file one. | "My attendance" for every staff member shows their own punches and leave requests. An admin approves or rejects; approved leave is paid unless the approver marks it unpaid. |
| 6 | "Generate salary" is one click that emails payslips. There is no review, no lock and no record of payment. | Draft with adjustments and reasons → finalise (locks the figures and the rules used) → payslips emailed → mark paid per person (question 2). |
| 7 | Staff appear as "Content / social" and "Office assistant", which aren't system roles, and the Roles tab has "+ New role". | A Roles screen with custom roles and the permission matrix, for the super admin only (§4.1). |
| 8 | Bonus withdrawals have only Approve, Reject and Mark as paid: no reason, no amount check, no history. | At least ৳ 500 and at most the available balance. Rejecting needs a reason. Every step is logged with who and when. |
| 9 | The wallet's ✕ deletes a transaction. | Reverse with a reason: a reversing entry, and the original stays. |
| 10 | The vault lists company and staff documents together. | Staff documents only, as asked. Company documents can reuse the same table later. |

## 4. Step 1 — Staff records and roles

### 4.1 Staff and roles

Screens: HR → Staff & bonus, and System → Roles & audit.

- **Staff list** on the shared admin table:
  - name and email, role, today's attendance (from step 2);
  - sales closed this month (bookings they own, confirmed this month) and bonus balance (from step 4);
  - row actions: WhatsApp, email, open, suspend.
- **Staff profile:** the HR record, role, status and documents. Attendance, salary and bonus tabs are added as those steps land.
- **Add staff:**
  - Fields: name, email, phone, role, designation and joining date.
  - An invitation email carries a one-time link, valid 72 hours, to set a password. "Copy invite link" shows the same link
    once, for WhatsApp.
  - The status stays `invited` until the password is set. Resending cancels the previous link.
  - There are no temporary passwords.
- **HR record** (`staff_profiles`, one per person):
  - designation, joining and leaving dates, date of birth;
  - NID number, encrypted with a lookup hash;
  - address and emergency contact;
  - the account salary is paid to (bank or bKash number), encrypted.

  Seen and edited with `staff.manage`. Each staff member sees their own record, read-only, in Profile.
- **Suspend and reactivate:**
  - Suspension ends sessions at once: refresh-token families are revoked, and the existing middleware already refuses
    access tokens.
  - A leaver is suspended with a leaving date.
  - Staff records are never deleted, because punches, payslips and ledgers point at them.
- **Role changes:**
  - Everyone has exactly one role.
  - `staff.manage` assigns any role except super admin.
  - Only a super admin grants or removes super admin, and the last one can't be removed.
  - Nobody changes their own role.
  - Every change is audited.
- **Roles screen** (super admin, `system.roles_manage`):
  - the roles table (users, whether the role sees the company balance) and "+ New role" for custom roles;
  - the permission matrix by module;
  - the design's staff-visibility toggles, which are the matching permissions.

  System roles can't be renamed or deleted. Super admin isn't editable, because it passes every check. Each grant and
  revoke is audited, and `permissions:sync` already respects the revocations.

### 4.2 Staff documents (the Vault design)

- **Table:** document (type and title), owner, number, expiry, status.
  - Status is derived from the expiry date: Expired, Expiring (within 30 days), Renew soon (within 90 days), Valid, or No
    expiry.
  - The thresholds are checked against the re-synced design.
- **Types:** passport, NID, driving licence, CV, appointment letter, contract, certificate, photo, other.
- **Protection as for customer passports, and one step more:**
  - the number is stored in an encrypted column with a lookup hash;
  - the file (PDF, JPG, PNG or WebP, up to 5 MB) is encrypted on the private disk and served inline with `no-store`;
  - every upload, replacement and archive is audited, and so is **every time someone opens a file**;
  - a replaced document is archived with a reason, never deleted.
- **By role:** new `staff_documents.view` and `staff_documents.manage` permissions, granted to Admin; the super admin
  always has access. Nobody else sees staff documents in this phase, the owner included.
- **Badge and alerts:**
  - The Vault badge counts expired and expiring documents.
  - A daily check alerts the Staff documents alert list 30 days before an expiry and again on the day, once each.

## 5. Step 2 — Biometric attendance

### 5.1 In the company app

- **Device card** (Attendance screen):
  - Shows the device's name and what the agent last reported: model, serial, firmware, address, enrolled users and fingerprints, records on the device, clock drift, agent version.
  - Also shows the last check-in, the last pull and its result, and the sync log.
  - Buttons: Sync now, Test link, Set device clock (shown when the drift is over 2 minutes), Rotate token, Revoke.
- **Device users:** the device's user list (the ID and the name as enrolled), each matched to a staff member or marked "ignore".
  Punches from unmapped IDs are kept, and count once the ID is mapped. A finalised payroll month isn't changed.
- **Daily attendance** is computed from punches in office time (Asia/Dhaka) and never stored as editable state:
  - in = first punch, out = last punch;
  - statuses:
    - full day; late in (after duty start plus grace); early out (before duty end); single punch;
    - approved leave; absent; day off; holiday;
    - not employed (before joining or after leaving); worked on a day off;
  - the design's monthly table, and the selected person's daily punches.
- **Corrections:**
  - An admin can set an in or out time, or mark a day as worked (official duty), with a reason.
  - A correction is a row of its own and is undone by another row.
  - The day shows the original punches next to the corrected times.
- **Leave:**
  - Every staff member files requests (dates and reason) in "My attendance".
  - `attendance.manage` approves them (paid or unpaid) or rejects them with a reason, and can record leave for someone.
  - Approved leave can be revoked with a reason.
  - Every step is logged, and the HR badge counts pending requests, as in the design.
- **Alerts:** if no pull succeeds for 60 minutes within duty hours on a working day, the Attendance alert list gets one
  message per outage. It says whether the office PC stopped checking in or the device stopped answering.
- **Permissions:** `attendance.view_all` (Admin, Accountant) and `attendance.manage` (Admin). "My attendance" needs no
  permission and shows only the person signed in.

### 5.2 The agent — the design to approve

**Why an agent:** the device answers only on the office LAN, and the office router gives the server no way in. Two
alternatives were rejected:
- **A VPN from the office to the VPS** needs router configuration plus a VPN endpoint on the shared server, which is a
  system-wide change.
- **The device's own cloud push (ADMS)**, where the firmware offers it, is usually plain HTTP identified by the serial
  number alone. It would need a public endpoint that accepts unauthenticated pushes.

```
 Office LAN                                        │  Internet
┌──────────────────┐   ZK protocol, TCP 4370  ┌────┴─────────────┐   HTTPS + device token   ┌──────────────────────────┐
│ ZKTeco K40       │ ◄──────── reads ──────── │ Agent on an      │ ─────── outbound ──────► │ api.bhabaghure.com.bd    │
│ fixed LAN address│                          │ office PC        │                          │ /api/v1/attendance-agent │
└──────────────────┘                          └────┬─────────────┘                          └──────────────────────────┘
                                                   │  nothing listens; no router ports opened
```

**Every minute**, Windows Task Scheduler starts the agent, never two at once. Each run:
1. **Checks in** with `POST /check-in`, sending its version, the PC clock and the last pull result.
   - The reply carries the pull interval (15 minutes) and any pending command from an admin: sync now, test link or set
     clock.
   - If the API can't be reached, the agent logs that and exits. Punches wait on the device.
2. **Pulls**, if 15 minutes have passed since the last good pull or a command asks for one:
   1. Connects to the device, with a 10-second timeout and the device's Comm Key if one is set.
   2. Reads the serial, model, firmware, clock, record counts, the user list (IDs and names only) and the attendance log.
   3. Sends the device report with `POST /device`: device info, user list, clock drift and the result. Test link stops
      here.
   4. Sends the punches the server hasn't acknowledged, 500 per request, with `POST /punches`. A punch counts as
      acknowledged only after a 2xx reply.
3. **Exits.** Anything that failed is retried the next minute, because the records are still on the device.

**What the agent never does to the device:**
- It never disables the device, so staff can punch during a read. A record added mid-read comes with the next pull.
- It never clears the log or changes users.
- It never reads fingerprint or face templates, passwords or card numbers.

The only write is the clock, and only when an admin presses "Set device clock". The time comes from the PC, which Windows
keeps in sync.

**Idempotency:**
- **Server:** a punch is unique on (device, device user ID, device time to the second).
  - A replayed or overlapping batch inserts nothing new. The reply counts `stored`, `duplicate` and `rejected` (with
    reasons).
  - If the agent loses its local store or is reinstalled, it resends the whole log, and every punch comes back as a
    duplicate.
- **Agent:** a local SQLite list of acknowledged punch keys stops it resending years of log every 15 minutes. It only
  saves effort; correctness rests on the server's key.
- **Device binding:** a token belongs to one device. The first report binds the device serial. A different serial is
  refused with 409 `device_changed` until an admin confirms the replacement on the device card.

**Offline and failure cases:**

| Situation | Result |
|---|---|
| Office internet down, PC off overnight, or API down | Punches wait on the device, which holds tens of thousands of records. The next good run sends them, dated when they happened. |
| Device unreachable (power, cable, LAN) | The device report says so, and the card shows "offline since". The alert rule in §5.1 applies. |
| A batch fails, or the agent crashes mid-send | Those punches aren't acknowledged, so they are sent again. The server counts them as duplicates. |
| Two agents holding the same token | The punch keys are the same, so each punch counts once. The sync log shows both agents checking in. |
| Device clock wrong (reset after a power cut, say) | The card shows the drift. Impossible times (in the future, or long before the device was registered) are rejected with that reason and not retried; those days are corrected by hand. The card offers Set device clock. |

**Security:**
- **Token:**
  - 256-bit random, shown once when the device is added, and stored as a SHA-256 hash.
  - Rotated or revoked from the card.
  - It reaches only the three agent endpoints, for its own device. Replies carry counts and commands, never staff data.
- **Transport:** HTTPS only, with certificates verified. No inbound port is opened on the PC or the router.
- **On the PC:**
  - The config and token live in `C:\ProgramData\Bhabaghure\Attendance`, readable only by SYSTEM and Administrators.
  - The token is encrypted with Windows DPAPI, and the task runs as SYSTEM.
  - Anyone with administrator rights on that PC could post invented punches, so use a PC whose administrator password
    staff don't have.
- **Abuse:** rate limits per token, and per IP for failed tokens. Every check-in, report and batch goes into the sync log.
- **Updates:** there is no auto-update, because a compromised update channel would run code inside the office. New
  versions are installed by hand, and the card shows the running version.
- **Cloudflare:** `api` is proxied. If bot protection challenges the agent, a WAF skip rule for
  `/api/v1/attendance-agent/*` is needed. The install test shows whether it is.

**Build and install:**
- **Code:** `agent/` in the repo, written in Python with the standard library plus **pyzk**, pinned by hash.
  - pyzk is the most widely used open-source ZK protocol library, and the K40/ID is on its tested list.
  - pyzk is GPL-2.0, so `agent/` carries that licence. The agent is a separate program talking to the API over HTTPS,
    so nothing else in the repo is affected.
  - pyzk's last release is 0.9 (2019). It is pinned, and would be vendored if a fix were ever needed.
- **Packaging:** PyInstaller, as a folder rather than a single file because it starts faster, which matters once a
  minute. The folder is zipped for install. The build isn't committed to the public repo; it can be rebuilt from the
  pinned requirements.
- **`install.ps1`**, run as administrator on the office PC:
  - asks for the API address, the device's address and port, the Comm Key and the token;
  - stores the token with DPAPI;
  - registers the task: every minute and at start-up, whether or not anyone is signed in, never two at once;
  - runs `agent.exe test`, which prints whether the device was reached (serial, users, records), whether the API was
    reached and accepted the token, and the clock drift.
- **Support:** `agent.exe test`, `agent.exe status` and `agent.exe pull --full`. Logs are kept 7 days and hold counts
  and errors only.
- **The PC:** 64-bit Windows 10 or 11, on the office LAN, switched on during office hours.

### 5.3 Data (company database)

| Table | Holds |
|---|---|
| `attendance_devices` | Name, bound serial, model, firmware, reported address, token hash, last check-in, last pull and its result, drift, counts, agent version, pending command, revoked at. |
| `attendance_device_users` | Device, device user ID, name on the device, staff member or ignored, who mapped it and when. |
| `attendance_punches` | **Append-only.** Device, device user ID, device time, verify type, punch state, batch. Unique on (device, user ID, time). |
| `attendance_sync_events` | **Append-only.** The sync log: check-ins, reports and batches, with counts and errors. |
| `attendance_corrections` | **Append-only.** Staff member, date, kind (in time, out time, worked), time, reason, by. Reversals are rows too. |
| `leave_requests`, `leave_request_events` | The request: staff member, from, to, reason, status, paid. The events (**append-only**): filed, approved, rejected, revoked, with who, when and the reason. |
| `attendance_rules`, `holidays` | The rules by effective month (§6), and holiday dates with names. |

## 6. Step 3 — Salary from attendance

**Rules** are set on the Attendance screen, as in the design (defaults in brackets). Each set is stored with the month it
takes effect, so earlier months keep their own rules.
- duty start (11:00) and end (19:00);
- grace, in minutes (0);
- pay for a late-in or early-out day, as a percent (50);
- working days per month (26);
- weekly off days (Friday), plus the holiday list;
- what a single-punch day counts as (a late-or-early day).

The design's option lists (0/10/15/30 minutes, 0/25/50/75 %) become presets, and any value in range is accepted.

**Salary per person:** the base monthly salary with the month it takes effect, kept as an **append-only** history with
who changed it and why. Visible to `payroll.manage`, `payroll.view` and the super admin, and to each person on their own
payslips.

**Pay for a month:**
- day rate = base ÷ working days (the setting);
- deductions = day rate × (absent days + working days not employed + (100 % − late-or-early pay) × late-or-early days);
- payable = base − deductions + adjustments, never below 0, rounded to the taka.

Approved paid leave, days off and holidays deduct nothing.

**Payroll run** (Attendance → Generate salary):
1. **Draft for a month:**
   - one row per person employed that month with a base salary, as in the design's table: full, late, early, leave,
     absent, base, payable, cut;
   - a day-by-day breakdown;
   - adjustments with reasons (an allowance, an advance recovered).

   Drafts recalculate as attendance changes.
2. **Finalise**, once the month has ended:
   - the figures, per-day statuses and the rules used are frozen;
   - payslip PDFs are emailed in each person's language, and each person sees theirs under "My payslips";
   - the super admin can reopen the month with a reason until anything is paid; after that, changes go into next month
     as adjustments.
3. **Mark paid**, per person: date, method and account, reference and receipt. Depending on question 2, this also
   records a cash-out under Salaries in the company books.

**Permissions:** `payroll.manage` (Admin) and `payroll.view` (Accountant).

**Tables:** `staff_salaries` (append-only), `payroll_runs`, `payroll_items`, `payroll_adjustments`.

## 7. Step 4 — Bonus accounts and My commission

- **`bonus_accounts`:** one per staff member, opened with the staff record. The balance is the sum of its ledger.
- **`bonus_transactions`** (append-only, and already named in `LedgerTables`):
  - credit or debit, and amount;
  - kind: `commission`, `manual`, `withdrawal` or `reversal`;
  - the booking or withdrawal it belongs to;
  - a snapshot of the commission rule used;
  - the entry it reverses (each entry can be reversed once);
  - reason, and who (none means the system).
- **Auto-credit when a booking is confirmed**, to the booking's owner, using the commission rules. **The rules are only in
  the re-synced design, which can't be read here yet.** The behaviour around them, to be checked against the design:
  - one credit per booking, so confirming again adds nothing;
  - cancelling a confirmed booking reverses the credit;
  - reassigning a confirmed booking reverses it for the old owner and credits the new one.
- **Manual credit and reversal** by `bonus.manage`, with a reason, audited.
- **Withdrawals:**
  1. The staff member requests one in My commission: at least ৳ 500 and at most the available balance, which is the
     balance minus open requests. The minimum is from your summary of the re-synced design. They can cancel while it's
     pending.
  2. `bonus.manage` approves it, or rejects it with a reason.
  3. Mark as paid, with date, method and account, reference and receipt. This writes the debit, and depending on question
     2 a company cash-out.

  Each step goes into `bonus_withdrawal_events` (append-only) and the audit log.
- **Screens:**
  - the Staff list's bonus column and the Bonus withdrawals card;
  - a bonus ledger on the staff profile, with manual credit and reverse;
  - My commission: the read-only half from Phase 5 §4.8 plus the live withdrawal form, laid out from the re-synced
    design.
- **403 tests** for every endpoint showing the company balance, another person's commission or profit, as Phase 5 §4.8
  requires.

## 8. Step 5 — Super admin wallet

The prototype is built as designed, except for one change:
- balance, total in, total out, and cash in this month;
- cash in or out:
  - amount above zero;
  - a source from the prototype's list of businesses, plus Personal and Other (editable);
  - a required reference, with saved references per direction that can be added and removed;
  - date and evidence (image or PDF);
- deals:
  - name, total, advance (no more than the total) and note;
  - payments recorded against what is due, with progress and history;
- breakdown by source, and history filtered by all, in or out;
- **the change:** the prototype's ✕ becomes **Reverse, with a reason**.

**Isolation:**
- **Database:** its own, `bhabaghure_wallet`, with its own MySQL user, **asked about before it is created**.
  - The company database user has no grant on the wallet database, and the wallet user has none on the company database.
    A company report *can't* read the wallet: MySQL refuses it, whatever the code does.
  - There is no foreign key in either direction; none is possible across the two users.
- **Code:** its own connection, models, routes and guard.
  - An architecture test fails the build if company code references wallet models or the wallet connection.
  - A test runs every company report and export and asserts that no query reached the wallet connection.
- **Evidence files:** in their own directory, encrypted with their own key rather than `APP_KEY`, so company-side code can't
  decrypt them.
- **Front end:** `wallet/`, its own SPA on its own subdomain, with the wallet's dark tokens (Phase 1 §8 item 14). It is never
  inside the admin.
- **Append-only:** `wallet_transactions`, deal payments included; the table is already in `LedgerTables`.

**Asked when step 5 starts:**
- creating the MySQL user;
- whether wallet sign-in is the super admin's staff login plus a second factor, or credentials of its own;
- the subdomain's DNS record, certificate and nginx site, which write outside /var/www/Bhabagure.

## 9. Permissions added

| Permission | Admin | Accountant | Sales agent | Tour operator |
|---|:-:|:-:|:-:|:-:|
| staff_documents.view, staff_documents.manage | ✓ | | | |
| attendance.view_all | ✓ | ✓ | | |
| attendance.manage | ✓ | | | |
| payroll.view | | ✓ | | |
| payroll.manage | ✓ | | | |

**Existing permissions put to use in this phase:** `staff.manage`, `bonus.manage`, `commission.view_own` /
`view_all` and `system.roles_manage`.

- The super admin passes every check.
- The wallet is for the super admin only, and no permission can grant it.
- `permissions:sync --add-only` brings the new permissions to production.

## 10. Tests

- **Step 1:**
  - the invite link is single-use, expires, and a resend cancels the old one;
  - role rules: the last super admin can't be removed, and nobody changes their own role;
  - suspension ends sessions;
  - custom roles and matrix edits are audited and respected by `permissions:sync`;
  - staff documents:
    - encrypted at rest;
    - 403 for every role without the permission, the owner included;
    - opening a file is audited;
    - expiry statuses.
- **Step 2, API:**
  - a bad, revoked or rotated token gets 401; a different serial gets 409;
  - the same batch twice stores nothing new, and overlapping batches work;
  - impossible times are rejected;
  - an unmapped user's punches count after mapping;
  - commands are delivered and reported once;
  - rate limits hold;
  - daily statuses at the edges: exactly at the grace limit, single punch, day off, holiday, before joining;
  - corrections and leave events are append-only.
- **Step 2, agent:**
  - unit tests with a fake device and a fake API: a replay after lost state, a batch that partly fails, an unreachable device, clock drift;
  - a test that the device wrapper allows only the permitted reads and the clock write.
- **Step 2, at the office:**
  - `agent.exe test` against the K40;
  - a punch appears in the admin within 15 minutes, or at once with Sync now;
  - with the network unplugged for a while, punches arrive once it's back.
- **Step 3:**
  - the design's sample month comes out exactly;
  - months with more or fewer working days than the setting;
  - joiners and leavers mid-month;
  - finalising freezes the figures, and reopening works only before payment;
  - each person sees only their own payslips.
- **Step 4:**
  - one commission per booking, reversed on cancellation and on reassignment;
  - withdrawal limits and states;
  - every step logged, and the ledger append-only;
  - My commission 403s.
- **Step 5:**
  - the company database user can't read the wallet database;
  - no company report or export touches the wallet connection;
  - reversal instead of delete;
  - an advance can't exceed the total, nor a payment what's due;
  - evidence encryption.
- **E2E:**
  - add and invite staff;
  - a staff document;
  - device mapping and a leave approval;
  - a payroll run through to paid;
  - a withdrawal through to paid;
  - the wallet's cash in, deal and reversal.

## 11. Questions

1. **Attendance agent (§5.2):** build it as designed, on an office Windows PC? The alternative is a small always-on Linux
   box (a Raspberry Pi or mini PC) running the same agent on a timer.
2. **Payouts and the company books:** when a salary or a bonus withdrawal is marked paid, should the system record a cash-out in
   the company cash book? It would go under Salaries or a new Staff bonuses expense account, with the money account,
   reference and receipt. The alternative is that HR records only the payment, and the accountant enters cash-outs
   separately.
3. **"Configurable per company":** does that mean this installation's own settings, with one company per install and
   every rule editable but nothing hard-coded? Or several companies in one install, each with its own staff, rules and
   payroll?

**Needed from you (not decisions):**
- **The re-synced design files.** They are needed for My commission and its rules (a Phase 5 leftover, and step 4), the
  Air ticketing layout check, and the Staff documents layout. Either copy them into `F:\Projects\Bhabagure\_design`, or
  run `/design-login` once in an interactive Claude Code terminal on this machine.
- **For the agent:** which office PC it goes on, and the device's Comm Key if one is set (Menu → Comm. → Comm Key; 0
  means none). Someone with administrator rights on that PC installs it.
