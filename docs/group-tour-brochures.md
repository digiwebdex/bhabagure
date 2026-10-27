# Photo brochures: a long, photo-led package PDF (2026-10-27)

**Asked:** the two group tours' brochures (Exotic Nepal Mustang Valley Trip, Kashmir Special Tour) as detailed,
landing-page style PDFs, using the photos already in the Group Tour Gallery (`docs/group-tour-gallery.md`), spread
through the brochure in a random-looking but fixed arrangement. Same delivery as before: the brochure PDF a signed-in
customer downloads from the package page (`docs/phase-8-visa-quotes-pricing-downloads.md` §4.E). Nothing invented:
every word, price and date comes from the package.

**Then (same day)** the Kathmandu–Pokhara–Ghandruk poster (package 25, a customized trip). **Decided with the client:**
every customized trip's brochure gets the same layout, keeping its price table; its gallery photos are those of the same
country and those linked to no tour, never another country's trip.

## 1. The layout (`api/resources/views/brochures/package.blade.php`, one template for both kinds)

1. The letterhead, as on every printed document.
2. **Banner:** the package's cover photo across the page (as tall as the photo at full width, kept between 80 and
   105 mm), the title, destination and length over its darkened foot, and chips: *Group Tour · Fixed Departure* or
   *Customized Trip*, the air ticket, the package code. Without a stored cover: the next of the package's photos; with
   none, a plain title.
3. **Key facts:** duration, destination, then
   - group tour: next departure, triple-sharing price per person;
   - customized trip: minimum travellers (else the hotel category, else "Customized"), the price per person for the
     group size chosen on the website.

   The summary when there is one; *Your choice* (room or hotel category × travellers, per person and total).
4. **Prices** (kept on one page):
   - group tour: price per person by room (triple, twin, single) beside a card per departure
     (*19 Nov 2026 – 25 Nov 2026*), each with its own price when prices differ by date;
   - customized trip: the table by hotel category and group size (1, 2, 4 … 12+), the chosen cell highlighted, and the
     room notes.
5. A row of three photos.
6. **Day-by-day itinerary** as a timeline, with a second row of three photos in the middle.
7. **Included / not included** with drawn ticks and dashes (the PDF's fonts have no tick glyph).
8. **Moments from our group tours:** every remaining photo, with its gallery caption, under "Photos from our earlier
   group tours", so a Nepal photo in the Kashmir brochure reads as the agency's earlier trip, not as Kashmir.
9. A closing panel: phones, email, website, Civil Aviation number.

## 2. The photos (`App\Services\Brochures\BrochurePhotos`)

- **Which, in this order:** the package's own photos after the cover; gallery photos linked to this package; linked to
  another package to the same destination (country); linked to no package. A group tour then takes the rest of the
  gallery (other countries' trips) as well; a customized trip does not. Each photo once; a draft never.
- **Order:** within each group, shuffled by the package (a CRC of package id and media id). Two brochures differ, but
  the same brochure is the same on every download. A new gallery photo finds its own place.
- **Never stretched, never cut:** photos sit in *justified rows*: one height per row, each photo at its own width for
  that height, so it keeps its shape and the row fills the page's width (188 mm, 2.5 mm gaps). A short last row joins
  the row before it. Only the banner is trimmed, as a banner.
- **Size:** inlined as JPEG (quality 82) from the 800 px *card* size (the banner from the 1600 px *detail* size).
  Chrome stores a WebP uncompressed in a PDF: the first try was 28 MB, as JPEG about 3 MB.
- **Stored PDFs** are made again when a gallery photo, its caption or link, or the package's photos change
  (`BrochurePhotos::fingerprint` in the cache key), and for template version 7.
- **Edits reach the next download (fixed 2026-10-28):** the stored PDF was keyed on the package's `updated_at`, which an
  edit of only the itinerary or the inclusions leaves as it was, so the old PDF was served until the next day. The key
  now also holds the printed itinerary, inclusions and destination (`BrochurePdf::printedContent`).
- **Worth doing in Admin → Website → Group tour gallery:** link the unlinked Kathmandu photos to a Nepal tour. Linked
  to no tour, they also appear in other countries' customized brochures (captioned with the place).

## 3. Checked

- `api/tests/Feature/PhotoBrochureTest.php`: every published photo printed once, drafts never, the same HTML on every
  render, the package's own gallery photos first; a customized trip shows its country's and unlinked photos but not
  another country's, a group tour all; photos inlined as JPEG; rows keep each photo's shape to 0.01 mm and never pass
  the page's width; without photos the brochure still has its title, prices and dates.
- Rendered with the live gallery's 17 photos (Bangla and English), group tours and package 25's table, and looked at
  page by page: no stretched photo, no text over text, the prices section never split from its heading.
