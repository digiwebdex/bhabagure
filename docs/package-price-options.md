# Package price options: what a package costs beside its own price

**Asked (2026-09-19):** the client sent six new packages (`_design/packages`: two Bhutan PDFs, a Thailand–Malaysia–
Singapore PDF and three flyers) and asked for each to show, neatly: the total for two travellers and for four, the price
with and without the air ticket, and with and without the domestic air ticket.

**Decided with the client (2026-09-19):**

- None of the packages includes the international air ticket; the flyers only estimate it. So the website shows the
  package price, and the air ticket as a separate estimate — never folded into a price the agency cannot promise.
- The table shows two and four travellers.
- The five older packages leave the website (unpublished, kept for their bookings); the six new ones replace them.
- Photos come from free-licensed photography found online.

## How a price option works

`tour_packages.price_options` (JSON, nullable): a short list, each entry one of two kinds —

| Kind | Fields | On the website |
| --- | --- | --- |
| **Extra** | `label_bn`, `label_en`, `extra_per_person` | Its own row in the price table: the package price **plus** the extra, per person and in all, for two and four |
| **Estimate** | `label_bn`, `label_en`, `estimate_bn`, `estimate_en` | Listed under “Paid separately (estimates)”, in words, never added to a price |

An option has an amount or an estimate, not both (`PackageRequest`; message `cms.price_option_amount_or_estimate`).
Blank text is stored as nothing and an empty list as `null`. The public API sends `priceOptions` with `{ bn, en }`
pairs, each language falling back to the other.

Examples on live: Amazing Thailand's domestic flight (Krabi → Bangkok) is an **extra** of 15,000 per person — 59,000
becomes 74,000 for two, 55,000 becomes 70,000 for four, exactly the flyer's “with domestic flight” prices. The Maldives
package's two tours (the sandbank fun day and the Sun Siyam Olhuveli resort day) are an extra of 28,500, which turns the
29,500 flyer into the 58,000 one — one package instead of two near-copies. The international air ticket, the Thai visa
and Bhutan's Sustainable Development Fee are **estimates**.

The price table's first row is the package price from the same pricing the booking uses (`packagePerPerson`, following
the hotel category chosen on the page), labelled “without air ticket” or “with air ticket” from `includes_airfare`. So
the table, the card, the stepper and the booking can never disagree. The booking then adds the site's service charge
and VAT (2% today, from the Pricing screen); the table says so in a line under it, so a customer who sees ৳ 59,000 for
two here and ৳ 60,180 at the payment step knows why. Booking itself is unchanged: an extra is chosen by telling the
office, which adds it to the invoice.

- Website: `web/src/features/packages/PriceTable.tsx`, under the price stepper on the package page and modal. One row per
  price; on a phone the label sits above the two prices.
- Admin: Packages → edit → **Price options** (`PriceOptionsField.tsx`), under the price grid.

## The six packages on live (2026-09-19)

Entered through the admin controllers (scratchpad `packages/enter-packages.php`), so the same rules and website refresh
apply as in the CMS. Prices are per person from a price grid (basic/3-star), so a group of four pays the four-person
price exactly as the flyers say; the flyers start at two, so the one-traveller price is the two-person price plus the
site's usual 12% single-room supplement, rounded to the hundred. Minimum two travellers.

| Code | Package | 2 people | 4 people | Extras | Estimates |
| --- | --- | --- | --- | --- | --- |
| Bhutan 01 | Western Gems, 5D/4N | 39,900 | 33,000 | — | SDF, air ticket |
| Bhutan 02 | Enchanting Bhutan, 8D/7N | 59,900 | 46,000 | — | SDF, air ticket |
| Thai 03 | Amazing Thailand, 8D/7N | 59,000 | 55,000 | domestic flight +15,000 | air ticket 37–50k, visa 6,000 |
| Thai 04 | Thailand, Malaysia & Singapore, 11D/10N | 95,000 | 95,000 | — | air tickets ~85,000, visas ~17,000 |
| Maldives 01 | Maafushi & Hulhumale, 4D/3N | 29,500 | 29,500 | two tours +28,500 | air ticket 58–70k |
| Maldives 02 | Maldives & Sri Lanka, 7D/6N | 69,000 | 69,000 | — | air ticket, Sri Lanka visa |

The Bhutan grids also carry the flyers' six- and ten-person prices. “Thai 02” was already taken by a draft from the old
website's import, hence Thai 03 and 04. Bhutan is a new destination (visa on arrival, as the PDF says).

**Photos:** Unsplash and Pexels refuse automated searches, so the photos come from Wikimedia Commons and Flickr through
Openverse, **commercial-use licences only** (CC BY, CC BY-SA, CC0). Every photo carries its photographer and licence in
the media library's credit, which the package gallery shows with a link to the source — that is what CC BY and BY-SA
ask for. Each was looked at before it was chosen, with the agency's family audience in mind.

**Offer banners:** the Mustang and Thailand Budget Escape banners pointed at packages that left the site, so they now
promote Maldives 01 and Thai 03 (same places in the slideshow). Banner artwork cannot show a credit, so both use
public-domain (CC0) photos only.
