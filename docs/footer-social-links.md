# Social links in the footer

**Asked (2026-10-02):** a row of social icons in the website's footer (Facebook, Instagram, TikTok, LinkedIn, YouTube,
WhatsApp), each opening in a new tab, with a hover animation, and the links filled in from the admin's settings. An icon
whose link is empty does not show, and a saved change reaches the website at once.

**Decided with the client (2026-10-02):** YouTube stays empty, so it has no icon, until they give the company's channel.

## Where the links live

`site_settings` key `contact`, beside the phone numbers and email, edited in **Admin → Site settings → Contact**.

- `facebook` and `instagram` were already there; `tiktok`, `linkedin` and `youtube` are new. Each is a full `https://`
  address or empty (`SiteSettingController::RULES`), and an empty one is saved as `null`.
- WhatsApp has no link field: its icon opens a chat with the main WhatsApp number (`contact.whatsapp`), the same chat
  as the website's other WhatsApp buttons.
- The seed (`packages/content-seed/settings.json`) carries the new fields empty. The contact details saved on live before
  2026-10-02 do not have them at all; the website reads a missing field as empty.
- Saving revalidates the website's `settings` tag, as every Site settings card does, so the footer changes at once.

## The footer

`web/src/features/footer/SiteFooter.tsx`, under the logo, on every page of the website and the portal.

- Round icons in the order Facebook, Instagram, TikTok, LinkedIn, YouTube, WhatsApp. A link left empty has no icon, and
  with none the row is not drawn.
- Each opens in a new tab (`target="_blank" rel="noopener noreferrer"`). Its name and "(opens in a new tab)" are read out
  in the visitor's language. The row is a list named "Follow us" / "আমাদের ফলো করুন".
- On hover an icon lifts a little and turns orange. Visitors who ask for reduced motion get the colour change only. All six
  fit on one line on a phone; a very narrow screen wraps them onto a second.
- The marks are in `web/src/components/brand/SocialGlyphs.tsx` (Simple Icons, CC0; Instagram drawn in outline) and
  `WhatsAppGlyph.tsx`. They take the text colour.

## A fix on the Site settings screen

Each card compared what is on screen with what was saved as JSON text, field order included. The API sends a saved value
back in its own field order, and a field added on the screen (a TikTok link, on contact details saved before it existed)
comes last. So after a good save the button still said **Save**. The card now compares field by field
(`canonical()` in `SettingsPage.tsx`).

## Tests

- API `CmsContentTest`: the links must be full https addresses; an empty one is saved as null; the public settings serve
  them.
- Admin e2e `social-links.spec.ts`: starting from contact details without the new fields, as on live, a bad link is
  refused, good ones are saved and the button says **Saved**, and an emptied link is saved as none. It fails on the old
  comparison.
- Website e2e (`live-data.spec.ts`): the footer shows an icon for each saved link in order, opening in a new tab, with
  none for an empty one; emptying a link removes its icon, in both languages.
