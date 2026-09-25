# Upcoming booking confirmation vouchers (2026-09-25)

**Asked:** an admin module, *Upcoming Booking Confirmation Voucher* (আপকামিং বুকিং কনফার্মেশন ভাউচার):

- a title field — the voucher or contract's description, or the company's name;
- upload of the confirmation voucher or contract, as PDF or JPG/JPEG;
- a list of the saved vouchers, where a click previews the file or downloads it.

## 1. What exists

- **System → Vault** (staff documents) already stores private files: uploaded as PDF/JPG/PNG, kept on the private disk,
  opened only through the API by signed-in staff, archived with a reason rather than deleted. It is for staff papers,
  so vouchers get their own screen, built the same way.
- **Booking e-tickets** attach a file to a booking and open it from the booking page.
- Nothing about vouchers exists yet.

## 2. Proposed design (the recommended answers; §3 asks)

**Admin → Sales → Vouchers** (`/vouchers`):

- **List:** title, the booking it belongs to (link), the service date, file type and size, who uploaded it and when.
  Upcoming ones first (soonest date on top), then past ones; a search box (title, company, booking number).
- **Upload** (dialog): title (required, e.g. "Hotel Himalaya — Kathmandu, 3 rooms, 12–15 Oct"), the file (PDF, JPG or
  JPEG, up to 10 MB), and optionally the booking and the service date.
- **Open:** a click opens the file in a new tab — a PDF in the browser's viewer, a photo as a picture — with a
  **Download** button beside it that saves the original file under its own name.
- **Archive** with a reason instead of delete; archived ones stay under an "Archived" filter.
- A voucher linked to a booking also shows in a *Vouchers* card on that booking's page.

**API:** table `booking_vouchers` (title, booking, service date, file path/type/size/original name, uploaded by,
archived at/by/reason); `GET /admin/vouchers`, `POST /admin/vouchers` (multipart), `GET /admin/vouchers/{id}/file`
(`?download=1` for a download), `POST /admin/vouchers/{id}/archive`. Files go to the private disk, never the public
one, and are served only to signed-in staff with the permission. New permissions `vouchers.view` / `vouchers.manage`,
reaching the live roles through `permissions:sync` on deploy.

**Tests:** API (upload rules — PDF/JPG only, 10 MB; list order and search; open and download; archive; permissions),
admin e2e (upload, list, open, download, archive, the booking card).

## 3. Decided with the client (2026-09-25)

All four recommended answers: an optional link to a booking, shown on its page; an optional service date, upcoming
first; admin and tour operator upload and archive, sales agent and accountant view and download; archive with a reason.

## 4. How it is built

- **API:** migration `2026_09_25_120000_create_booking_vouchers_table.php`, model `BookingVoucher` (morph
  `booking_voucher`, audited as `voucher.uploaded` / `voucher.archived`), service `Documents\BookingVouchers` (file
  encrypted with APP_KEY on the private disk, as e-tickets are; PDF/JPG only — extension and content type both checked —
  up to 10 MB), `Admin\BookingVoucherController` (`index` with `view=upcoming|past|archived` and `search`, counts per
  view; `store` resolves `booking_reference` among bookings the uploader can see; `file` inline or `?download=1` as an
  attachment under the original name, never cached; `archive`). Permissions `vouchers.view` / `vouchers.manage` in
  `RolesAndPermissionsSeeder`; `permissions:sync --add-only` gives them to the live roles on deploy. The booking detail
  carries `vouchers` (null without the permission) and `actions.upload_voucher`.
- **Admin:** `features/vouchers/` — `VouchersPage` (Sales → Vouchers, `/vouchers`), `VoucherDialogs` (upload,
  archive), `api.ts` (list, upload, archive, open in a new tab, download under the original name);
  `features/bookings/VouchersCard.tsx` on the booking page.
- **Smoke:** the list refuses a request without a token.

## 5. Tests

- **API** `BookingVouchersTest`: upload (PDF and JPG; wrong type, no title, over 10 MB, unknown booking refused), stored
  encrypted, opened inline and downloaded under its name, on the booking's detail, audited; Upcoming / Past / Archived
  order, search and counts; archive needs a reason and happens once, archived files still open; sales agent and
  accountant read and download but can't upload or archive; nobody signed out lists or opens one.
- **Admin e2e** `vouchers.spec.ts`: upload a PDF linked to a booking and a JPG, upcoming order, open in a new tab,
  download with its own name and bytes, the booking's card, archive with a reason and find it under Archived; a sales
  agent sees the list without the upload button.
