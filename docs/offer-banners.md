# Offer banners: the slideshow under the search

**Asked (2026-09-18):** the client wants the offer banners shown as a slideshow, second on the home page — straight
under the hero video and above the package and flight search.

**Moved (2026-09-19):** the client asked for the search panel above the banners, so the home page now runs hero
video → search → offer banners.

**Decided with the client (2026-09-18):** it changes by itself every 6 seconds, with arrows and dots; each banner can
lead wherever they choose; the banners sit in a page-width card about 3:1; and they upload the pictures themselves.

## Website

`web/src/features/offers/OffersSlideshow.tsx`, `id="offers"`, after `SearchPanel`. Hidden until a
banner is published.

- One picture at a time in a rounded, page-width card, cropped 3:1. The first is loaded with priority: it sits just
  under the search, near the top of the page.
- **It waits rather than runs away:** the timer stops while someone is pointing at the slideshow, while anything inside
  it has keyboard focus, and while the tab is in the background. Under `prefers-reduced-motion: reduce` it never moves
  on its own, and jumps rather than glides when the visitor asks for the next one.
- **Every way of moving works:** swipe (native scroll snapping), the two arrows (wider screens), and the dots. The
  scroll position is the single source of truth, so the dots always match what is on screen.
- Each banner's title is its alt text, so a screen reader hears what the picture says; the slides carry
  `aria-roledescription="slide"` with "Offer 2 of 4", and the section is labelled as a carousel.
- A banner leading to a page of this site (`/packages/…`) keeps the visitor's language through next-intl's `Link`;
  anything else opens in its own tab.

## Admin → Website → Offer banners (`/offer-banners`, `cms.manage`)

The usual published list: what the banner says in both languages, the picture from the media library, an optional
destination, then publish and reorder. A banner is its picture, so publishing needs one. The link must be a page of
this site (starting `/`) or a full `https://` address — nothing else is accepted, so a banner cannot carry a
`javascript:` or `http:` link.

## API

Table `offer_banners`, model `OfferBanner`, `OfferBannerController` under `/admin/offer-banners`,
`GET /public/offers`. Saves refresh the website through the `offers` cache tag, added in all four places
(`RevalidateWebsite`, the website's `/api/revalidate`, `deploy.sh revalidate_all`, `smoke.sh`). The website loads the
endpoint with an empty fallback, because a deploy builds the website before the API serves the route. The media library
refuses to delete a picture a banner still shows.

## The demo banners on live (2026-09-18)

The client asked for demo banners from free photography. Unsplash refuses automated downloads, so the photos come from
**Pexels** (free for commercial use, and already this site's placeholder source), composed into 1600 × 533 banners in
the brand's own style — orange pill, Bengali headline in Hind Siliguri, price, and a call to action:

| Banner | Photo | Leads to |
| --- | --- | --- |
| নেপাল মুস্তাং অ্যাডভেঞ্চার · ৳ ৭৫,০০০ থেকে | mountain lake | the Mustang package |
| থাইল্যান্ড বাজেট এস্কেপ · ৳ ২৭,৫০০ থেকে | beach | the Thailand package |
| এয়ার টিকেট, হোটেল ও ভিসা | Kathmandu street | the contact block |

They are placeholders for the client's own artwork: the prices are the live package prices, and every banner can be
replaced on that screen without touching the site.

Artwork advice for whoever draws the next one: 1600 × 533 (the 3:1 the card crops to), and any text set large — on a
phone the banner is about a quarter of that width, so a headline below roughly 90 px stops reading. Keep the words in
the left two-thirds, where the darkened side of these banners sits.

## Tests

- API `OfferBannersTest`: a picture is needed to publish; order and unpublishing; only a site page or an `https://`
  address is accepted as a link; a picture in use cannot be deleted; permissions. `CmsPermissionsTest` covers the path.
- Admin e2e `cms.spec.ts`: publishing is refused without a picture, then the banner reaches the public API.
- Website e2e `live-data.spec.ts`: published banners appear under the hero and the search panel, with the picture,
  the link and working dots.
