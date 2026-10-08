# Legal pages in the admin

**Asked (2026-10-08):** the booking terms, cancellation policy and privacy policy on the website were drafts written
during the build and don't match the company's own terms. Staff need to edit them in the admin and change them later
themselves. (The rest of the site's fixed text — the home page's hero, services, why us, how it works, FAQ and about —
comes next, in a second step.)

**Decided with the client (2026-10-08), each as recommended:**
1. The three legal pages first; the home page's text afterwards.
2. Each page in Bangla and English; English left empty shows the Bangla.
3. The blog's formatting editor (headings, bold, lists, links).
4. Every publish is kept as a version, and a booking records the terms version its customer accepted.

## What staff do

**Admin → Website → Legal pages** lists the three pages: Booking terms and conditions, Refund and cancellation policy,
Privacy policy. Each shows which version is on the website.

- **Edit** opens the page's editor. Before the first publish it holds the website's built-in draft, with the
  `[CLIENT TO CONFIRM]` points to fill in; after that, the version on the website.
- **Publish** (after a confirmation) saves a new version and puts it on the website at once. An optional "What changed"
  note goes in the version list.
- **Versions** lists every version, newest first, with who published it and when; **Read** opens one as it was.

Nothing is overwritten and nothing is deleted. To go back to an earlier text, open it, copy it into the editor and
publish it as a new version.

## How it works

- **API:** table `legal_page_versions` (slug `terms` · `refund-policy` · `privacy`, version, titles and sanitised HTML in
  both languages, note, who, when), written only by `App\Services\Cms\LegalPages::publish` (versions numbered under a
  lock, audited as `legal_page.published`). Admin routes under `cms.manage`: `GET admin/legal-pages`,
  `GET admin/legal-pages/{slug}`, `GET admin/legal-pages/{slug}/versions/{n}`, `POST admin/legal-pages/{slug}`.
  The editor's starting text is `packages/content-seed/legal.json`, made once from the website's drafts.
- **Website:** `GET public/legal` gives each page's current version, or null. Pages with a version show it (no draft
  banner, indexed by search engines); pages without one keep the built-in draft (`web/src/features/legal/documents.ts`,
  with its banner and `noindex`). A publish refreshes the website's new `legal` tag, added in the four usual places
  (`RevalidateWebsite`, the website's `/api/revalidate`, `deploy.sh`, `smoke.sh`).
- **Bookings:** `bookings.terms_version` is `terms-v<n>` for the version the customer accepted, or the draft's name
  (`draft-2026-09`) before the terms were first published. The booking page in the admin shows it.

## Tests

- API `LegalPagesTest`: drafts until published and the editor's starting text; versions numbered, sanitised, kept and
  readable; English falling back to Bangla; permissions; the terms version on a booking.
- Admin e2e `legal-pages.spec.ts`: the refund policy rewritten from the draft and published twice; the website gets the
  latest and the earlier version still reads as it was. Writing it found that typing straight after a publish could be
  lost when the editor restarted from the new version; a publish now finishes only after that reload.
- Website e2e (`live-data.spec.ts`): published terms replace the draft (title, headings, no banner, indexable) in both
  languages, and English left empty shows the Bangla.
