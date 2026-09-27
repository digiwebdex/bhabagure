# Customer reviews with trip photos (2026-09-25)

**Asked:** a section where customers rate their trip and write a review (feedback), and can add photos of the trip
when they submit it.

## 1. What exists

- **"What travellers say"** (`#reviews` on the home page) shows reviews from Admin → Website → Reviews: a quote (Bangla
  and English), name, trip, 1–5 stars, draft → published. Only staff write them; there are **none on live yet**, so the
  section is hidden.
- **Public forms** (contact, hotel quote) already have per-address limits and a hidden anti-bot field.
- **The media library** re-encodes uploads to WebP and drops the phone's location data.
- **The customer portal** signs in with a one-time code, which can't reach customers until SendGrid or SMS works — a
  portal-only review form couldn't be used today.

## 2. Proposed design (the recommended answers; §3 asks)

**Website — "Share your trip" form** (under "What travellers say", and a *Write a review* button there):

- Name, mobile number, which trip (the package, or "other"), travel month, 1–5 stars, the review (20–1500 characters,
  in Bangla or English), and **up to 5 photos** (JPG/PNG, 8 MB each).
- Sent reviews wait for staff; the form says so ("Thank you — your review will appear once our team has checked it").
- Anti-spam as on the other public forms, plus a limit per mobile number.

**Admin → Website → Reviews** gains a **Pending** tab: each submission with its stars, text, photos (click to enlarge)
and — when the mobile number matches a booking — "✓ Travelled with us: BH-…". Staff **approve** (it goes live) or
**reject**; they can fix spelling and pick which photos show. Staff-written reviews keep working as now.

**Website display:** each review card shows its photos as small thumbnails that open larger; approved reviews of a
package also show on that package's page. A "✓ Verified traveller" mark when the number matched a booking.

**API:** `reviews` gains `source` (staff | customer), `phone`, `booking_id` (matched), `rejected_at`; a `review_photos`
table (media, order, shown); `POST /public/reviews` (multipart, throttled); the admin review endpoints gain
pending/approve/reject. Photos go through the media library (WebP, location data removed).

**Tests:** API (the form's rules, photos, spam limits, pending until approved, the booking match, approve/reject,
public shape), admin e2e (approve with photos), website e2e (submit with a photo, see it after approval).

## 3. Decided with the client (2026-09-25)

All four recommended answers: anyone on the website, checked by staff; shown only after approval; up to 5 photos; on
the home page and the reviewed package's page.

## 4. How it is built

- **API:** migration `2026_09_25_140000_customer_reviews_with_photos.php` (`reviews.source`, `phone`, `booking_id`,
  `reviewed_at`/`_by`, `rejected_at`, `reject_reason`; table `review_photos` with `is_shown`); `Review` scopes `pending`
  and `listed`; `ReviewPhoto`; `Services\Reviews\ReviewSubmissions` (submit — photos through the media library first, so
  a bad picture saves nothing; booking match on confirmed/completed bookings by the customer's or a traveller's number;
  approve/reject once, audited; show/hide a photo); `Public\PublicReviewController` (`POST /public/reviews`, throttle
  `public-forms`, three a day per number, the hidden `company` field); admin `GET reviews/pending` (with `meta.total`),
  `POST reviews/{id}/approve|reject`, `PUT review-photos/{id}`. The Reviews list shows staff and approved reviews only;
  deleting a review deletes its photos. Sidebar badge `reviews` (cms.manage). The media library refuses to delete a
  review's photo. The public review carries `packageSlug`, `verified` and the shown photos — never the number.
- **Website:** `features/reviews/ReviewForm.tsx` ("Share your trip", in a modal), `ReviewPhotos.tsx` (thumbnails and an
  enlarged view — plain images of the API's own sizes, not the website's image optimiser, after the 2026-09-24 memory
  outage), `ReviewsSection.tsx` always shown (the form, then the cards or "be the first"); the package page lists its
  reviews with its own form. `lib/reviews-api.ts` sends multipart and maps the API's field errors.
- **Admin:** `features/cms/reviews/PendingReviews.tsx` above the Reviews list: stars, text, number, trip, month, the
  booking match, photos with a Show tick (saved at once, undone if the save fails), Approve / Reject with a reason.

## 5. Tests

- **API** `CustomerReviewsTest`: a review with photos waits, staff see it with its number and photos, a photo hidden,
  approved once, live with the shown photo and never the number, audited; rejected never shows; the booking match
  marks it verified; the form's rules, five photos at most, pictures only, 8 MB, the honeypot, three a day per number,
  nothing half-saved; only website staff decide. `NavCountsContractTest` covers the badge.
- **Admin e2e** `customer-reviews.spec.ts`: the badge counts it; staff untick a photo and approve; it goes live with one
  photo.
- **Website e2e** `live-data.spec.ts`: the form refuses an incomplete review, sends one with a photo; after approval it
  shows on the home page with its photo (opened larger), without the number or a verified mark, and on its package page.
