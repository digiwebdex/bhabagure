# Group tour brochures: a long, photo-led PDF (2026-10-27)

**Asked:** the two group tours' brochures (Exotic Nepal Mustang Valley Trip, Kashmir Special Tour) as detailed,
landing-page style PDFs, using the photos already in the Group Tour Gallery (`docs/group-tour-gallery.md`), spread
through the brochure in a random-looking but fixed arrangement. Same delivery as before: the brochure PDF a signed-in
customer downloads from the package page (`docs/phase-8-visa-quotes-pricing-downloads.md` §4.E). Nothing invented:
every word, price and date comes from the package.

At the time there were exactly two group tours (packages 13 and 24), so the change covers group tours as a whole. A
customized trip's brochure (`brochures/package.blade.php`) is unchanged.

## 1. The layout (`api/resources/views/brochures/group-tour.blade.php`)

1. The letterhead, as on every printed document.
2. **Banner:** the package's cover photo across the page (as tall as the photo at full width, kept between 80 and
   105 mm), the title, destination and length over its darkened foot, and chips: *Group Tour · Fixed Departure*, the
   air ticket, the package code. Without a stored cover: the next of the package's photos; with none, a plain title.
3. **Key facts:** duration, destination, next departure, triple-sharing price per person; the summary when there is
   one; *Your choice* (triple sharing × the travellers chosen on the website).
4. **Prices and dates** (kept on one page): price per person by room (triple, twin, single with their supplements) beside
   a card per departure (*19 Nov 2026 – 25 Nov 2026*), each with its own price when prices differ by date.
5. A row of three photos.
6. **Day-by-day itinerary** as a timeline, with a second row of three photos in the middle.
7. **Included / not included** with drawn ticks and dashes (the PDF's fonts have no tick glyph).
8. **Moments from our group tours:** every remaining photo, with its gallery caption, under "Photos from our earlier
   group tours", so a Nepal photo in the Kashmir brochure reads as the agency's earlier trip, not as Kashmir.
9. A closing panel: phones, email, website, Civil Aviation number.

## 2. The photos (`App\Services\Brochures\GroupTourPhotos`)

- **Which:** the package's own photos after the cover, then the gallery's published photos linked to this package, then
  the rest of the published gallery. Each photo once; a draft never.
- **Order:** within each of those three groups, shuffled by the package (a CRC of package id and media id). The two
  brochures differ, but the same brochure is the same on every download. A new gallery photo finds its own place.
- **Never stretched, never cut:** photos sit in *justified rows*: one height per row, each photo at its own width for
  that height, so it keeps its shape and the row fills the page's width (188 mm, 2.5 mm gaps). A short last row joins
  the row before it. Only the banner is trimmed, as a banner.
- **Size:** inlined as JPEG (quality 82) from the 800 px *card* size (the banner from the 1600 px *detail* size).
  Chrome stores a WebP uncompressed in a PDF: the first try was 28 MB, as JPEG it is about 3.4 MB.
- **Stored PDFs** are made again when a gallery photo, its caption or link, or the package's photos change
  (`GroupTourPhotos::fingerprint` in the cache key), and for template version 6.

## 3. Checked

- `api/tests/Feature/GroupTourBrochureTest.php`: every published photo printed once, drafts never, the same HTML on
  every render, the package's own gallery photos first; rows keep each photo's shape to 0.01 mm and never pass the page's
  width; without photos the brochure still has its title, prices and dates.
- Rendered with the live gallery's 17 photos (Bangla and English) and looked at page by page: no stretched photo, no
  text over text, the prices section never split from its heading.
