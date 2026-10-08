# Logo and browser icon in Site settings

**Asked (2026-10-09):** an option in Site settings to update the logo.

**Decided with the client (2026-10-09), each as recommended:**
1. The logo changes **everywhere**: the website and customer portal, the admin, and printed documents.
2. **Two logos:** one for white backgrounds, a light one for the dark blue backgrounds.
3. The **browser icon** can be changed too: a square image.
4. Each one can go back to **the original**.

## What staff do

**Admin → Site settings → Brand: logo and icon** has three slots, each with a preview on the background it sits on:

| Slot | Used on |
|---|---|
| Logo | the website header; invoices, quotations, payslips and brochures |
| Light logo | the website footer, the customer portal header, the admin's sidebar and sign-in page |
| Browser icon | the browser tab and a phone's home screen, on the website, portal and admin |

**Choose image** opens the media library (upload a new file there, or pick one). **Use the original** goes back to
the file that ships with the site. **Save** applies all three; the website changes within a minute. A PNG with a
transparent background works best; the icon must be square, at least 180 × 180 (512 × 512 is best).

## How it works

- **Setting:** `site_settings` key `brand` = `{ logo, logoLight, icon }`, media-library ids or null (the original).
  `PUT admin/settings/brand` (the Site settings permission) checks each id exists and that the icon is square.
  `App\Support\Brand` reads it.
- **Website and admin** get URLs in `GET public/settings` as `logos` (`brand` there is already the company's name): the
  media library's 800-px WebP for logos, its 400-px WebP for the icon, or null. The website's `BrandLogo` component and
  the admin's `useBrandLogos` fall back to the original files. The website's icon comes from page metadata, so the two
  original icons moved from `web/src/app/` to `web/public/brand/` (Next.js lets an icon file in `app/` win over
  metadata). The admin sets its own tab icon in the page once the settings load.
- **Printed documents:** `InvoiceView::letterhead` embeds the chosen logo (else `config('bhabaghure.invoices.logo_path')`);
  invoices, quotations, payslips and brochures all use that letterhead. Stored invoice, quotation and brochure PDFs are
  keyed on `Brand::fingerprint()`, so they are made again with a new logo.
- **Media library:** an image in use as a logo or icon can't be deleted.
- **Not changed:** the website's 404 page keeps the original logo. It is deliberately static (that keeps its language
  right), so it can't read settings. The wallet has no logo.

## Tests

- API `BrandLogoTest`: originals until chosen; chosen URLs, the PDF letterhead and the cache fingerprint; in-use media
  can't be deleted; the icon must be square; permissions; reset back to the originals.
- Admin e2e `brand-logo.spec.ts`: a light logo uploaded through the card shows in the sidebar at once and in the public
  settings; "Use the original" brings it back.
- Website e2e (`live-data.spec.ts`): chosen logos in the header and footer and the chosen icon in the page; the
  originals again after reset.
