# Two-step sign-in with an authenticator app (2026-11-13)

**Asked (owner):** "make system authenticator in setting, I will manually add the 2FA authenticator for login".

**Decided with the owner (2026-11-13, the recommended answers):**
1. **Each person chooses**, in their profile; the super admin can **require it for everyone** in Site settings → Security.
2. **The personal wallet asks for the code too**, for someone who has it on. Otherwise the wallet, which signs in with the
   same staff password, would be the way in with the password alone. (The wallet's own authenticator, removed on
   2026-10-09 at the owner's request, stays gone: this is the staff account's, shared by both.)
3. **A lost phone:** ten one-time recovery codes at setup, and the super admin can reset someone's.

## What staff see

**Profile → Two-step sign-in** (`/profile`):
- *Set up* → *Show the QR code*: a QR code to scan with Google Authenticator, Microsoft Authenticator, Authy or any app
  that does TOTP, and the setup key to type instead. The first code from the app turns it on.
- Then the **ten recovery codes**, once: copy or download them, tick "I've saved my recovery codes", Done. They are
  never shown again.
- When on: the date, how many recovery codes are left (red at 3 or fewer), **New recovery codes** (with a code from the
  app; the old ones stop working) and **Turn off** (the password and a code). Turn off isn't offered while the super
  admin requires it.

**Sign-in** (admin and wallet): the email and password as before; with two-step sign-in on, a second screen asks for the
6-digit code, or a recovery code. A browser already signed in stays signed in: the code is asked only at a new
sign-in. "Forgot password?" still works, and the code is still asked afterwards, so a reset link alone doesn't open the
account.

**When the super admin requires it:** anyone with a login who hasn't set it up signs in with the password and lands on
"Set up two-step sign-in"; the admin does nothing else until it is done (the API refuses every admin call with
`two_factor_setup_required`, and a screen left open goes to the setup page on its next request).

## The super admin

- **Site settings → Security** (only the super admin sees the card): *Require two-step sign-in for everyone*, with how
  many of the people with a login have it on. Switching it on asks for confirmation; switch it on only once your own is
  on.
- **Staff → a person → Role & access:** "Two-step sign-in: On/Off", and **Reset two-step sign-in** for someone who lost
  their phone and their recovery codes. They are signed out everywhere and sign in with the password alone until they
  set it up again (at once, if it is required). Not for your own: turn yours off in your profile, or ask another super
  admin.

## How it is kept safe

- **The secret** is per person, kept encrypted with `APP_KEY` (`staff.two_factor_secret`), and leaves the server only
  while it is being set up. Never in `/me`, the staff record, a log or the audit.
- **Each code works once:** the time step of the last code accepted is stored, and a code is taken only by an update
  that moves it forward, so two requests can't both use one (admin and wallet included).
- **Wrong codes:** each counts against the sign-in's own limit (5 a minute per email and address), and 10 for one
  account within 15 minutes from anywhere stop all codes for it until the 15 minutes pass. The setup and management
  calls are limited to 10 a minute per person.
- **Recovery codes:** "k7m2-9xq4" form, any case, with or without the dash; kept only as HMAC-SHA256 hashes keyed with
  `APP_KEY`, so the database alone can't use them; each is crossed off when used.
- **The rule** is a private site setting (`staff_security`), never in the website's public settings.
- **Audit:** `auth.two_factor.enabled`, `.disabled`, `.reset`, `.recovery_codes_renewed`, `.recovery_code_used`,
  `.wrong_code`, `.policy`; a sign-in with a code is `auth.staff.login` with `two_factor: code|recovery`.

## API

| | |
|---|---|
| `POST staff/auth/login` | takes `code` too. With two-step sign-in on and no code: 401 `two_factor_required`; a wrong one: 401 `two_factor_invalid`; too many: 429 `two_factor_locked`. A recovery code used: `recovery_codes_left` in the answer |
| `GET staff/auth/two-factor` | `enabled`, `confirmed_at`, `recovery_codes_left`, `required` |
| `POST staff/auth/two-factor/setup` | `secret`, `uri` (otpauth://); replaces one not confirmed yet |
| `POST staff/auth/two-factor/confirm` | `code` → on, and `recovery_codes` once |
| `POST staff/auth/two-factor/recovery-codes` | `code` → new `recovery_codes` |
| `POST staff/auth/two-factor/disable` | `password`, `code` (422 `two_factor_required_by_policy` while required) |
| `POST admin/staff/{id}/two-factor/reset` | super admin, not their own |
| `GET`/`PUT admin/security` | super admin: `two_factor_required` |
| `POST wallet/auth/password` | takes `code`; 401 `code_required` / `invalid_code`, 429 `too_many_attempts` |

The `staff/auth/two-factor` calls are outside `staff.can-work`, so someone made to set it up can reach them.

Code: `App\Support\Totp` (RFC 6238, HMAC-SHA1, 30 s, 6 digits, one step either side; the wallet's class of 2026-09,
brought back), `App\Services\Auth\TwoFactor`, `TwoFactorController`, `SecurityController`; admin
`features/auth/TwoStepSetup.tsx`, `TwoStepSetupPage.tsx`, `features/profile/TwoStepCard.tsx`,
`features/cms/settings/SecurityCard.tsx`; the QR code is drawn in the browser with `qrcode-generator`, so the secret
never goes to another site.

## Tests

- API: `TwoStepSignInTest` (setup and confirmation, nothing secret in any answer or readable in the database, the
  code after the password, a code used twice, recovery codes once and in any case, the per-account limit, turning off,
  the rule and its enforcement, the super admin's reset signing the person out), `WalletSignInTest` (the wallet's code,
  and a code the admin took not opening the wallet), `TotpTest` (RFC 6238's vectors).
- Admin e2e `two-step.spec.ts`: set up from the profile with codes made as the app makes them, a wrong code, sign-in
  with a code and with a recovery code, turning off; Security shown to the super admin only.
