# Website features: on and off for the public

**Asked (2026-10-07):** an on/off switch for each public website feature that is managed in Admin → Website, so a
feature can be hidden from the public website without touching its content or its admin screen. Off is not delete:
switched on again, everything comes back as it was.

**Decided with the client (2026-10-07), each as recommended:**
1. The switches are a card in **Admin → Site settings**, "Website features", saved like the other cards. No new menu item.
2. **Reviews off** also stops new reviews: the "Share your trip" form is hidden and the API refuses submissions.
3. **Blog off** hides the whole Blog section, the newsletter sign-up card at its foot included.
4. **Team off** keeps the About section's story and figures, without the people, the "Our team" links or the page.

## The switches

Blog, Team, Reviews, Gallery, Visa services, Travel host, Airline partners, Offer banners, Group tour photos: the
Website menu's content screens, in its order. Packages and departures are the catalogue, not website extras, and stay
out of this; so do the hero video and the media library.

Every feature is **on** until switched off. Before the card is first saved there is no setting at all, and the website
shows everything exactly as before this change.

## How it works

- **The setting:** `site_settings` key `features`, `{ blog, team, reviews, gallery, visas, creator, partners, offers,
  tourPhotos }`, each a boolean. `PUT admin/settings/features` (the Site settings permission, as every card) needs all
  nine; unknown keys are dropped. `App\Support\WebsiteFeatures` reads it, with any missing feature counting as on.
- **The website gets it** in `GET public/settings` as `features`, always with all nine filled in. Saving revalidates
  the website's `settings` tag, as every Site settings card does, so the change shows at once.
- **One place on the website** (`web/src/lib/content/features.ts`, applied in `loadContent`) leaves a switched-off
  feature's content out of what the website renders. Everything that already hides when a feature has no content then
  does so: its home section, its header, ☰ sheet and footer links, its pages (a direct address gives "not found") and
  its sitemap entries. Nothing is deleted or changed in the database; the public API still lists the content.
- **Checked by name** where a section shows even when empty:
  - the home page's Reviews section ("be the first") and Blog section (with the newsletter card);
  - the reviews and review form on each package page;
  - the About section's team column and "See the whole team" link;
  - the `/ourteam` page and its sitemap entry.
- **Reviews off:** `POST public/reviews` answers 409 `reviews_off` with a message in the customer's language, and
  stores nothing.

The admin screens are not affected by any switch: Blog, Team, Reviews and the others work as usual whatever the website
shows.

## Tests

- API `WebsiteFeaturesTest`: all on before anything is saved; validation; a switch saved as a boolean reaches the public
  settings and revalidates the website; only Site settings editors may change it; reviews refused while off and taken
  again when on, with the blog's posts untouched. `PublicContentContractTest` expects `features` in the public settings.
- Website unit `website-features.test.ts`: each switch empties only its own content; no switches means nothing changes.
- Website e2e (`live-data.spec.ts`): Blog, Team and Reviews off remove their sections, links, pages (404), package-page
  reviews and sitemap entries while About stays; switched on, all of it is back.
- Admin e2e `website-features.spec.ts`: the card's nine switches, Blog switched off and on, and the Blog screen still
  working meanwhile.
