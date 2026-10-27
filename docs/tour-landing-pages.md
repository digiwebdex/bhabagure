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
7. **Prices and inclusions:** room prices for the countdown's departure (the package page's own calculation), included,
   not included.
8. **Book your seat:** Book Now, Ask on WhatsApp, Download brochure (sign-in and logging unchanged), phones and email.

**Book Now** everywhere opens the website's existing booking form on this tour and that date: no booking or payment
of its own, no price of its own. The package page links to its landing page ("See this tour's full story page").

## 3. Home page: "Upcoming group tours" (`UpcomingToursSection.tsx`)

After the offer banners: a card per page with **Show on the home page** on, whose tour has a departure to come — its
photo, title, dates, "N days to go", seats left, the price, "Booking open" with a dot that pulses three times (still for
visitors who ask for less motion), linking to the page. In the pages' order, then the soonest. On phones the cards are a
swipeable row. A tour whose dates have all passed drops off by itself; its page stays, saying the next dates will be
announced. A cancelled departure is never counted down to. Drafts, unpublished tours: nothing on the website.

## 4. Admin: Website → Tour landing pages

New page → choose the group tour (its address starts as the package's) → save → banner, optional title and
introduction (the package's shown as placeholders), photos beside each day (each day's title shown; photos left on a
day the package no longer has are flagged), countdown (automatic or a chosen upcoming date), Show on the home page →
Publish (only while the package is published). The package editor links to its landing page, or offers to make one.
A photo a page uses can't be deleted from the media library. Website staff (`cms.manage`) manage pages; audited as
`cms.tour_landing_page.*`.

## 5. API

- Admin: `GET/POST /admin/tour-landing-pages`, `GET .../options` (group tours, and which have a page),
  `GET/PUT/DELETE .../{id}`, `POST .../{id}/publish|unpublish`, `PUT .../order`.
- Public: `GET /public/tour-pages` — published pages whose package is published; the website's cache tag `tour-pages`,
  also refreshed with `tour-photos`, `packages` and `departures`.
- Tables `tour_landing_pages`, `tour_landing_day_photos` (migration `2026_10_28_110000`).

## 6. Not yet

- **The matching brochure PDF** (next phase, approach approved in principle): the same page printed by the PDF
  renderer — slideshow as a grid of every photo, the countdown as a fixed date, Book Now as a link.
- **Staff preview of a draft** on the website: a page is seen only once published.

## 7. Checked

- `api/tests/Feature/TourLandingPageTest.php`; `admin/e2e/tour-pages.spec.ts`; `web/src/lib/website-features.test.ts`.
- Looked at locally with the live Mustang package's content and photos (dev database): desktop and phone widths, both
  languages, home page card. Website browser tests (`web/e2e`) not run: the PC hasn't the memory for the Next build.
