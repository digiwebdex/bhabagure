# EMI preview in the booking form (2026-10-22)

**Asked (the client):** in the website's online booking form, below "Room type", an "EMI অপশন দেখুন" button that shows
what EMI would cost on this booking — per bank and tenure: the charge %, the extra charge, the total and the monthly
instalment — using SSLCommerz's printed EMI rate chart. Staff must be able to edit the percentages. Information only:
no change to payment processing, the gateway or the amount a customer pays.

**Decided with the client (2026-10-22), each as recommended:**
1. The calculation is an **estimate**, labelled as such (SSLCommerz's chart gives the rates, not how they are applied):
   `extra = amount × rate ÷ 100` (rounded to the taka), `total = amount + extra`, `monthly = total ÷ months` (rounded
   **up** to the taka, so the instalments cover the total). Change `web/src/lib/emi.ts` if SSLCommerz confirms otherwise.
2. The button sits **after the add-ons** in step 1 — still below Room type, and the amount already includes any add-on.
3. Banks as **tabs**, "Other banks (35)" first.
4. **Booking form only.**

## The rates (SSLCommerz chart, card and EMI charges included)

| Months | Standard Chartered | The City Bank | Other banks (35) |
|---|---|---|---|
| 3 | 5.67 | 5.65 | 5.13 |
| 6 | 7.86 | 7.27 | 6.75 |
| 9 | 10.74 | 9.51 | 8.99 |
| 12 | 13.77 | 11.85 | 11.33 |
| 18 | 17.65 | 15.55 | 15.03 |
| 24 | 23.25 | 20.90 | 20.38 |
| 30 | not offered | 22.32 | 21.80 |
| 36 | 31.07 | 26.78 | 26.26 |

Minimum booking amount: BDT 5,000. Standard Chartered's 30 months is "not offered", never 0 %. "Other banks (35)" is
the chart's "For All (35) Banks" rate group: its member banks aren't listed, so the website says their eligibility is
still being confirmed with SSLCommerz and asks the customer to check before choosing EMI. No card processing fee is
added on top: the chart's rates include it.

## What customers see

Step 1 of **Book online**, after the add-ons: **"EMI অপশন দেখুন / See EMI options"** (with "from about ৳ … a month"). It
opens a panel:
- the amount used — the booking's whole total for these travellers, room, add-ons and charges (not the per-person room
  price), updating whenever any of them changes;
- below the minimum, an eligibility message instead of plans;
- otherwise the bank tabs, and for each tenure the group offers: months, charge %, extra charge, total, per month;
- the estimate notice: the bank confirms the final EMI, chosen when paying by card; nothing is added to the booking.

Opening or closing it changes nothing in the form; the booking's total stays the same.

## What staff do

**Admin → Site settings → EMI rates**: a switch for the whole preview, the minimum amount, and the chart — tenures as
rows, the three groups as columns; an empty box is "not offered". Per group: shown on the website or not, and "eligible
banks still to be confirmed". Saving updates the website at once.

## How it works

- **API:** site setting `emi` (`App\Support\Payments\EmiRates`: `{ enabled, minAmount, groups[{ key, name, enabled,
  eligibilityPending, rates{ "3": 5.13, …, "30": null } }] }`), validated in `SiteSettingController` (3 known groups,
  each rate 0–100 % with up to 2 decimals or null). Migration `2026_10_22_100000_emi_rates.php` stores the chart **only
  when no `emi` setting exists**, so a re-run never overwrites staff edits. `GET public/pricing` carries `emi`: null when
  switched off, else the groups that are on, each with only its offered tenures.
- **Website:** `features/booking/EmiPreview.tsx` in `PackageStep`, given `quote.total` from `BookingModal` (the same
  `quoteBooking` the API checks); the figures from `lib/emi.ts`. The built-in seed (`packages/content-seed/pricing.json`)
  carries the same chart.
- **Admin:** `features/cms/settings/EmiRatesEditor.tsx`.

## Tests

- API `EmiRatesTest`: the chart as served (no 30 months at Standard Chartered, the pending group); staff edits reach the
  website; the migration run again keeps them; switched off; validation. `PublicContentContractTest`: the seed matches.
- Website unit `lib/emi.test.ts`: the figures, rounding, a tenure not offered.
- Admin e2e `emi-rates.spec.ts`: a rate changed and a tenure emptied in Site settings, read back by the website's API.
- Website e2e (`live-data.spec.ts`, "the EMI preview under step 1…"): below Room type; the 12-month figure; no 30 months
  at Standard Chartered; closing keeps the form; more travellers re-price it; the booking total equals the amount used;
  the minimum. Writing it found the panel being squeezed under the add-ons when the step was taller than the screen
  (an overflow-hidden flex child in the scrolling body): it no longer shrinks.

## Not done (needs separate approval)

Choosing or paying by EMI, any gateway change, or adding EMI charges to a booking or payment.
