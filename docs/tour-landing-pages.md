# Tour landing pages: a group tour's own page to share (2026-10-28)

**Asked:** the client did not want the package page with an overview added; they want a dedicated, shareable, long,
photo-led page per tour — like the Nepal itinerary brochure they sent and the trip page on airnestbd.com/travel —
staff can make for any tour later, with the matching brochure to download. In the client's words: প্রত্যেকটা ট্যুরের
জন্য আলাদা ল্যান্ডিং পেইজ তৈরি করতে পারবে, সকল ডিটেইলস থাকবে, আলাদা পেইজ হিসেবে পোস্ট হবে।

**Decided with the client (2026-10-28), the recommended answer each time:** the address is `/tours/<slug>`
(`/en/tours/<slug>`); the photos beside each day are chosen on the landing page (the day's text stays the package's);
a new "Upcoming group tours" strip on the home page, the "Upcoming departures" section kept; the page first, the
matching brochure PDF as the next phase (until then the existing brochure is downloaded).

## 1. What is whose

| Owner | Content |
|---|---|
| The **package** (Catalogue → Packages) | Title, duration, prices and room supplements, departures (dates, prices, seats, cancelled), each day's text, included / not included, overview, package photos |
| The **gallery** (Website → Group tour photos) | Earlier trips' photos, captions, months, tour links |
| The **landing page** (Website → Tour landing pages) | Address, the package, draft / published, banner photo (blank: the package's cover), up to three photos beside each day (by day number), the countdown's departure (blank: the next one), home page promotion and order |
| Landing-page overrides (optional) | Page title and introduction; blank uses the package's title and overview |

Nothing about the tour is copied: the page reads the package every time, so a price or date changed there is on the
page at once. Only group tours (fixed departures) get a landing page, one each; a package doesn't get one by itself.

## 2. The page (`web/src/features/tour-pages/TourLandingPage.tsx`)

1. **Banner:** the photo across the screen, destination and length, the title, chips (group tour, air ticket, code),
   Book Now and the next departure.
2. **The tour in brief:** duration, destination, next departure, price per person.
3. **Introduction:** the page's own, else the package's overview; none: no section.
4. **Upcoming departure:** its dates, price and seats left, a countdown (days, hours, minutes to 00:00 Dhaka on the
   date, by the visitor's clock — gone once the date has come), Book this date; every other date with its own Book.
5. **Day by day:** a large "Day N", the day's title and text as on the package, and its photos — one large, two side
   by side, or one large and two beneath — alternating sides; a day without photos is text only. Then "Ready to join
   this tour?" with Book Now.
6. **Trip memories:** the gallery's photos in the brochure's order and rule (docs/group-tour-brochures.md §2), in the
   home page's slideshow, each captioned, under "From our earlier group tours" — never presented as the days above. A
   photo already on the page (banner or beside a day) is not repeated. With the gallery switched off in Site settings,
   the section goes.
6a. **What travellers say**, **What other guests say about us** (আমাদের সম্পর্কে অন্যান্য গেস্টরা কী বলেন) and **Watch
   vlogs about this tour** (এই সম্পর্কিত ভ্লগ দেখুন): reviews and videos, each hidden when empty (§9).
7. **Prices and inclusions:** room prices for the countdown's departure (the package page's own calculation), included,
   not included.
8. **Book your seat:** Book Now, Ask on WhatsApp, Download brochure (sign-in and logging unchanged), phones and email.

**Book Now** everywhere opens the website's existing booking form on this tour and that date: no booking or payment
of its own, no price of its own. The package page links to its landing page ("See this tour's full story page").

## 3. Home page: "Upcoming group tours" (`UpcomingToursSection.tsx`)

After the offer banners, for pages with **Show on the home page** on whose tour has a departure to come (2026-10-29):

- **The banner:** the one leaving soonest (with seats; the soonest of all when every one is full) as a large photo
  across the section — "Booking open" with a soft ring that widens and fades every 2.4 s (no blinking; still for
  visitors who ask for less motion), "Guaranteed departure" when staff marked the date so, destination and length, the
  title, the dates, a live countdown (days, hours, minutes), a bar of seats booked out of the total with the seats left,
  the price and "See the full plan →". The whole banner links to the page.
- **More group tours:** the others as smaller cards beneath — photo, title, dates, "N days to go", the seat bar, the
  price — in the pages' order, then the soonest; a swipeable row on phones.

Nothing at all when no page qualifies. A tour whose dates have all passed drops off by itself; its page stays, saying the next dates will be
announced. A cancelled departure is never counted down to. Drafts, unpublished tours: nothing on the website.

## 4. Admin: Website → Tour landing pages

New page → choose the group tour (its address starts as the package's) → save → banner, optional title and
introduction (the package's shown as placeholders), photos beside each day (each day's title shown; photos left on a
day the package no longer has are flagged), countdown (automatic or a chosen upcoming date), Show on the home page, reviews put first, guests' videos and travel
vlogs (§9) → Publish (only while the package is published). The package editor links to its landing page, or offers to make one.
A photo a page uses can't be deleted from the media library. Website staff (`cms.manage`) manage pages; audited as
`cms.tour_landing_page.*`.

## 5. API

- Admin: `GET/POST /admin/tour-landing-pages`, `GET .../options` (group tours, and which have a page),
  `GET/PUT/DELETE .../{id}`, `POST .../{id}/publish|unpublish`, `PUT .../order`.
- Public: `GET /public/tour-pages` — published pages whose package is published; the website's cache tag `tour-pages`,
  also refreshed with `tour-photos`, `packages`, `departures` and `reviews`.
- Tables `tour_landing_pages`, `tour_landing_day_photos` (migration `2026_10_28_110000`); `tour_landing_reviews`,
  `tour_landing_videos` (migration `2026_10_29_100000`, §9).

## 6. Staff preview of a draft (2026-10-28)

Admin → Website → Tour landing pages → the page → **Preview** (website staff, `cms.manage`): a link for Bangla and one
for English, working 30 minutes, to the page **as saved**, draft or not, marked "Preview — not public".

- The token (page id and expiry, signed with the app key: `App\Support\LandingPreviewToken`) is after the link's `#`,
  which browsers never send. The page (`/tours/preview`) posts it to the website's `/api/tour-preview`, which checks it
  with the API and keeps it in an HttpOnly, SameSite=Strict cookie, then reloads without it. From there it travels only
  in the `X-Preview-Token` request header to `GET /public/tour-pages/preview` — so no address, server log, application
  log or analytics ever holds it. The audit log records that a link was made, not the token.
- Expired, tampered, for another page, or missing: a plain 404 from the API and "This preview link has expired or isn't
  valid" on the website — never any of the draft.
- The preview is never cached (`no-store`), never indexed (`noindex`), records no visit, loads no cookie banner or GA4,
  and is in no public list, home page card or sitemap. The address `preview` is reserved.

## 7. The matching brochure PDF (2026-10-28)

The brochure **is the landing page printed**: the website's print view of it (`/tours/<slug>/print`, and
`/tours/preview/print` for a draft) — the same words, photos and look, laid out for A4 (`TourPrint.tsx`).

**On the web page → in the PDF:** the slideshow → every memory photo in a grid, each its own shape, captioned; the
countdown → "Departs 19–25 Nov 2026 — 22 days from 28 Oct 2026, the date of this brochure" and every date with its
price (no seats left: they change by the hour); Book Now → a link to the page with `?book=1&date=…`, which opens the
website's booking form on that date while it has seats (else the page's next date) — nothing is booked by the link;
WhatsApp, phone and email are links too, and the addresses are printed. Scripts don't run in the PDF.

**Pages:** A4 portrait, as long as the content. The cover is the banner on the whole first page; then the tour in brief,
introduction and dates; each day kept whole **when it fits on a page** — a longer day splits between paragraphs, its
heading always with the text that follows; the photo grid's rows and list items never split; long price and inclusion
lists split between items. Fonts: the website's own (Hind Siliguri, Bricolage), embedded; ticks and crosses are drawn,
not glyphs.

**Rendering** (`App\Services\Brochures\LandingBrochurePdf`, `PageRenderer`, `packages/pdf/src/render-page.mjs`): Chrome
opens the print view at `WEBSITE_INTERNAL_URL` (the website on the server itself, `http://127.0.0.1:3340`) and may load
exactly: that page (200, no redirect followed); the website's own static files under `/_next/static/` (GET, 200); and the
page's photos — banner, day photos, memories — served from JPEG copies the API prepares (never fetched). Everything else
is blocked and the render fails rather than print incomplete. The invoice renderer (`render.mjs`, no network at all) is
unchanged. One render at a time; the page has 20 s, fonts and photos 10 s more, within the renderer's 60 s.

**Downloads:** the same address, customer sign-in and Downloads log. With `BROCHURE_LANDING_PDF=true`, a package with a
**published** landing page gets this PDF; anything else — off, no page, a draft page, a missing photo file, the website
not answering, a blocked or failed load, a timeout — gets the photo brochure, as before, with a warning in the log. Only a
complete PDF is stored (`brochures/landing/<page>/<key>.pdf`, one day's copies kept).

**Made again when** anything printed changes: the page's presentation (title, introduction, banner, day photos,
countdown date), the reviews it shows and the videos it lists (§9), the package (its row, itinerary, inclusions, destination), its departures, the gallery photos and
captions it shows, prices and room supplements, the office's contact details, the logo, the release deployed (the
website's design: `App\Support\Release`, the repository's commit) and the Dhaka date.

**Review renders** (on the server's command line only; drafts through the preview, never a public address; nothing
stored for customers): `php artisan brochures:landing-pdf <slug> bn|en --out=<file>`.

**Switching it on** (separate approval): `BROCHURE_LANDING_PDF=true` in `api/.env`, then `deploy/deploy.sh
--reload-config`. **Rollback:** `BROCHURE_LANDING_PDF=false` and `--reload-config` — every download is the photo brochure
again at once; or revert the commit and deploy. No nginx, firewall or system setting is involved.

## 9. Reviews, guests' videos and travel vlogs (2026-10-29)

All three are optional; a section with nothing in it is not shown, on the page or in the PDF.

**Reviews — "What travellers say".** The tour's own published reviews (Website → Reviews, linked to this package) show
by themselves, in the reviews' usual order. Staff may put up to 12 reviews **first**, in their own order — this tour's or
another tour's — in the page's editor ("Put a review first"). At most 12 in all. Only published reviews show: one taken
off the website leaves the page by itself (and drops out of the editor's picks on its next save). The same cards as the
home page: two rows a page, "Read more", the customer's photos. With **Reviews** switched off in Site settings, the
section goes (the videos stay). New reviews are still added in Website → Reviews.

**Videos — "What other guests say about us" and "Watch vlogs about this tour".** Up to 12 links in each, in staff's
order, each with optional Bangla and English titles and an optional picture from the media library. Only YouTube
(`youtube.com/watch`, `youtu.be`, `shorts`, `live`, `embed`) and Facebook (`facebook.com`, `m.`/`web.`, `fb.watch`)
links are taken.

- **YouTube:** a still (staff's picture, else YouTube's own `i.ytimg.com` image) and a play button. Nothing of YouTube's
  player loads until the visitor clicks; then it plays in the card from `youtube-nocookie.com`.
- **Facebook:** a card (staff's picture, else a plain blue card) that opens the video on Facebook in a new tab — its
  player can't be embedded without Facebook's script, which the website doesn't load.

**In the PDF:** reviews as quote cards (stars drawn, the reviewer and trip; a long review stops near 520 characters at
a word, with "…"; no customer photos — the renderer loads only the page's own photos), and the videos as a list of
titled links under "Watch the videos". Neither: nothing.

**Admin:** Website → Tour landing pages → the page: cards **Reviews** (the tour's count, Put a review first, ▲▼,
Remove; link to Website → Reviews), **Guest videos** and **Travel vlogs** (Add video, link, titles, picture, ▲▼,
Remove). A picture a video uses can't be deleted from the media library. Saved with the page; audited with it.

**API:** `featured_review_ids` (published review ids, ≤ 12) and `videos` (`section` guest|vlog, `url`, `title_bn`,
`title_en`, `thumbnail_media_id`; ≤ 12 per section) on `POST/PUT /admin/tour-landing-pages`; the editor gets
`review_choices` (every published review, this tour's first). Public `tour-pages` adds `reviews` (the home page's review
shape) and `videos` (`section`, `provider`, `url`, `youtubeId`, `title`, `thumbnail`).

## 10. Checked

- API: `TourLandingPageTest`, `TourLandingPreviewTest`, `LandingBrochurePdfTest` (choice, sign-in and logging, the
  allowlist manifest, prepared JPEGs, fallbacks, nothing stored on failure, every freshness trigger incl.
  presentation-only changes, a review and a video, release and Dhaka date); reviews featured first and in order, other
  tours' picks, drafts refused and dropped, video links recognised and refused, 12 per section, the picture kept in the
  media library.
- Admin: `admin/e2e/tour-pages.spec.ts` (make, preview link, a review put first, a vlog refused then taken, promote,
  publish, the public payload).
- Website: unit tests (`tour-booking.test.ts`: the Book link's date, the brochure's Dhaka date; `website-features.test.ts`:
  reviews off empties the page's reviews and keeps its videos).
- Headless Chrome on the developer's PC (2026-10-29), phone (390 px) and desktop (1366 px): the home banner and smaller
  card, the reviews, both video sections and the print view's quote cards and links; no sideways scrolling; no request
  to YouTube or Facebook before a click, and the click loads the `youtube-nocookie.com` player.
- Real renders on the developer's PC (Bangla and English): Mustang-like content, an introduction with day photos, long
  itinerary text with a day longer than a page; fallbacks for a missing photo and a renderer failure. A headless-browser
  check of the preview: banner, token gone from the address and from every request, noindex, no analytics, bad link
  refused.
- Not run: the website's browser tests (`web/e2e`) — this PC lacks the memory for the Next build, and there is no CI.
