# Payment methods and airline partners

**Asked (2026-09-18):** the client sent their "Payment Methods" sheet (every card, wallet and bank SSLCommerz takes) and
an "Our Airlines Partners" sheet, and asked for the payment marks on one line at the bottom and the airline logos on the
home page.

**Decided with the client (2026-09-18):** the payment line sits in the footer, still (not moving); the airline band
closes the home page, under the contact block and above the footer.

## Payment methods, in the footer

`web/src/features/footer/SiteFooter.tsx`, above the copyright, on every page of the website and the portal.

- One image, `web/public/payments/pay-with-sslcommerz.png` — SSLCommerz's own "Pay With" strip, which already carries
  every method on the client's sheet and the "Verified by SSLCommerz" mark, and which they keep current as banks and
  wallets are added. Served from our own site rather than hotlinked, and sized by the image optimizer.
- It is wider than a phone, so the line scrolls sideways there rather than shrinking the marks past reading. Its `alt`
  names the methods in the visitor's language, for anyone who cannot see it.
- It says what the gateway accepts. Until SSLCommerz is switched on (`PaymentOptions::checkoutAvailable()`), bookings
  are still paid by bank transfer or bKash from the booking page — the strip does not promise checkout.

## Airline partners

A published list, exactly like the other website lists.

- **API:** table `airline_partners` (names in both languages, `media_id`, optional `website_url`, status, order), model
  `AirlinePartner`, `AirlinePartnerController` under `/admin/airline-partners`, `GET /public/partners`. A partner *is*
  its logo, so publishing needs one. The media library refuses to delete a logo in use.
- **Refresh tag `partners`**, added in all four places: `RevalidateWebsite`, the website's `/api/revalidate`,
  `deploy.sh revalidate_all` and `smoke.sh`. The website loads the endpoint with an empty fallback, because a deploy
  builds the website before the API serves the route.
- **Website:** `web/src/features/partners/PartnersSection.tsx` — a quiet band on the page's own background, each logo on
  a white tile that lifts on hover. With a website address the tile opens the airline's site in a new tab. The band is
  hidden until an airline is published, and its heading is small and plain: it is a trust mark, not a section to read.
- **Admin → Website → Airline partners:** name in both languages, the logo from the media library, an optional website,
  then publish and reorder like any other list.

### The logos on live (2026-09-18)

The client's sheet shows eight airlines. Logos are trademarks, and most airline logos on Wikipedia are held there under
fair use, which does not cover a commercial site — so only ones published under a free licence (Wikimedia Commons,
PD-textlogo) were used, and the rest are for the client to upload from their own brand material:

- **Published:** Scoot, AirAsia, Biman Bangladesh Airlines.
- **Draft, awaiting the client's word:** IndiGo (read from the dotted mark on their sheet; publish it in the admin if
  that is right).
- **For the client to upload:** US-Bangla Airlines, Malaysia Airlines, Batik Air, Lion Air.

## Tests

- API `AirlinePartnersTest`: a logo is needed to publish; order and unpublishing; a logo in use cannot be deleted;
  link validation and permissions. `CmsPermissionsTest` covers the new path.
- Admin e2e `cms.spec.ts`: the screen refuses to publish without a logo, then publishes and reaches the public API.
  Writing it turned up a real bug: the media picker is a dialog inside the editor dialog, and React passes the picker's
  close event up the tree, so choosing an image closed the editor and lost what was typed. `components/ui/feedback.tsx`
  now takes only its own dialog's close — that also repairs the Gallery and Team screens.
- Website e2e: the band appears after publishing, in both languages, under the contact block; the footer's payment line
  carries the right image and alt text.
