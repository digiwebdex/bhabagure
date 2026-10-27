# The package page: a shareable, complete tour plan (2026-10-28)

**Asked:** a link the office can share so a customer opens it and scrolls through the whole, detailed tour plan, and
staff can later make similar pages and edit their photos, itinerary and prices. **Decided with the client:** reuse the
existing package page (no separate landing-page system or switch), and build three things: the Group Tour Gallery's
photos of the tour on its page, an overview, and *Duplicate* for packages. Per-day "overnight" and "travel" fields were
not chosen: that information goes in the day's text.

## 1. The page (audit, 2026-10-28)

`https://bhabaghure.com.bd/packages/<slug>` (Bangla; `/en/packages/<slug>` in English) is public: no sign-in, and the
whole plan is in the server's HTML, so link previews and search engines read it too. It shows the title, the package's
photos, price per person with group-size or room prices, a group tour's departures (dates, price, seats left), the
day-by-day itinerary, what is and is not included, reviews, and *Download brochure* (sign-in, logged — unchanged),
*Ask on WhatsApp* and *Book*.

## 2. Added

- **Overview** (`overview_bn`, `overview_en`, up to 10,000 characters each): Catalogue → Packages → a package →
  *Overview*. Shown under the price strip on the page (and in the package modal) and after the key facts in the brochure
  PDF, with staff's line breaks kept. Empty: no section. One language empty: the other is shown. The *summary* stays
  the one line on the package card.
- **Photos from this tour:** the Group Tour Gallery's published photos linked to the package (Website → Group tour
  photos → *Tour*), with their captions and months, in the same slideshow as the home page, after the inclusions. No
  "See this tour" link there, as it would point at the page itself. None linked, or the gallery switched off in Site
  settings: no section.
- **Duplicate** (the package editor's *Publishing* card; `POST /admin/packages/{id}/duplicate`, packages.manage): a new
  draft with the package's text, overview, itinerary, inclusions, prices, room rates and tags; the code gets `-COPY`
  (`-COPY2`…), the address `-copy` (`-copy-2`…), the title "(copy)". Not copied: photos, departures, bookings, the
  featured mark. Audited as `cms.package.duplicated`. Staff then change the title, code and address, add photos (and
  dates for a group tour) and publish as usual.

## 3. Content the page needs from the office

The page shows only what is entered. For the Mustang Valley Trip at the time: no summary or overview yet; place
descriptions and distances only as far as the day texts give them; two Bangla days carried stray `**` marks.

## 4. Checked

- `api/tests/Feature/PackagePageTest.php`: the overview saved, blank saved as none, public with the language fallback,
  in the brochure, no empty brochure section; duplicate copies what it should and not photos or dates, the original
  untouched, next free code and address, draft not on the website, only packages.manage.
- `admin/e2e/package-page.spec.ts`: write an overview, save, duplicate, land on the new draft.
- **Not run: the website's browser tests (`web/e2e`).** Their Next.js build needs about 7 GB of free virtual memory; the
  developer's PC had 3.7 GB, and the project has no CI (no `.github/workflows`, no workflow runs). Checked instead on
  the live site, signed out: Mustang's page has the photo section with 5 photos and captions in both languages and no
  link to itself; Kashmir (no linked photos) has none; no package has an overview yet, so none is shown. The overview
  on the page is therefore not yet seen with real text.
- **Intermittent admin test, cause not established.** In one run of `cms`, `group-tours`, `package-page` and
  `price-grid` (2026-10-28), `group-tours.spec.ts:65` ("a group tour's dates each have a price…") failed at
  `rows.nth(1).getByRole('radio').check()` with "Clicking the checkbox did not change its state" (the departure's
  "Show on the card" choice). Run again with `group-tours` and `package-page` only, it passed. That rerun left out
  `cms.spec.ts`, so neither slowness on that PC nor data left by an earlier spec is ruled out. Nothing in this change
  touches the departures panel; to settle it, run the four specs together again, more than once.
