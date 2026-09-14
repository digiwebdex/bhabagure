# Phase 2 — public website and CMS

**Status (2026-09-13): Phase 2 is built — the website runs on live API data, edited through the admin CMS.** See
[`phase-2-cms-api.md`](phase-2-cms-api.md) for the API, the editors and the checks.

Decisions on the four open questions:

1. **Section order: your list.** Header → hero → search (trip + air-ticket tabs) → marquee strip →
   services → packages → departures → why us → how booking works → reviews → FAQ → gallery →
   about → blog (newsletter card at its foot) → contact → footer.
2. **Reviews and gallery are CMS-managed** (`reviews`, `gallery_items` tables; same image upload as
   packages). Each section is hidden until it has real items.
3. **Modal plus real pages.** Cards open the modal and the URL becomes `/packages/<slug>`; direct
   visits render the full page. Blog cards show an excerpt linking to `/blog/<slug>`.
4. **Website first, on seed data.** API, CMS editors and uploads follow once Composer and Docker
   are installed and both schema docs are approved.

Sources: `_design/README.md` §1, `Bhabaghure Website.dc.html` (read in full and rendered at 1280px and
390px), screenshots 01–05, `data/wp-trips.json`, and the decisions in your Phase 2 brief (§6).

---

## 1. How it fits together

```
Laravel API ── public read endpoints ──▶ Next.js website (server components, cached per tag)
     │                                          ▲
     │ ◀── CMS writes (staff JWT, permissions) ─┤ admin app: package / blog / team editors
     │                                          │
     └── on save: POST /api/revalidate ─────────┘ (secret; refreshes only the changed content)
```

- Section content renders on the server. Only the interactive parts ship JavaScript: search,
  package grid pricing, modals, forms, FAQ accordion, chat assistant, reveal and count-up.
- Every money figure comes from the API, through `@bhabaghure/pricing` and `@bhabaghure/format`.
- **Until Composer and Docker are installed** the website runs on the same seed JSON the Laravel
  seeders will load (`packages/content-seed/`). Seed mode must be switched on explicitly
  (`CONTENT_SOURCE=seed`). There is no default, so a server without the setting fails to build
  instead of quietly serving seed or demo content.

---

## 2. Component structure — website (`web/src`)

```
proxy.ts                               host + locale routing (Phase 1)
app/
├── api/revalidate/route.ts            on-demand cache refresh, called by Laravel on CMS save
├── sitemap.ts, robots.ts
└── [locale]/
    ├── layout.tsx                     <html lang>, fonts, providers
    └── site/
        ├── page.tsx                   home — fetches content, composes the sections in order
        ├── packages/[slug]/page.tsx   package page for direct visits and sharing (reuses PackageDetail)
        └── blog/[slug]/page.tsx       full post

features/SiteChrome.tsx                header, footer, floating actions, chat, modals — wrapped by each
                                       page, so each page passes its own path to the language toggle

components/
├── ui/        Button (cta · primary · outline · ghost), Pill, Chip, Stepper, Field (label + inline
│              error + red border), Input, Select, Modal (backdrop click, ×, Esc, focus trap,
│              max-h-modal, inner scroll), SectionHeading (orange rule + h2 + lede), Badge, SeatsBar
├── motion/    Reveal (IntersectionObserver, 20px fade-up, 70ms stagger), CountUp (runs on scroll-in),
│              ScrollProgress
└── brand/     Logo (dark / light), WhatsAppGlyph

features/
├── header/        SiteHeader (sticky; 73px ≥900px, two rows/120px below), NavLinks, MenuSheet
│                  (☰; "Book" opens the booking modal, never navigates), AccountPill, LanguageToggle
├── hero/          HeroVideo (muted autoplay loop + poster + scrim, outlined headline), Marquee
├── search/        SearchPanel (tabs), TripSearchForm, ResultCounter, AirQuoteForm
├── services/      ServicesSection, ServiceCard
├── packages/      PackagesSection, RegionChips, PackageGrid, PackageCard (whole card clickable;
│                  inner Book stops propagation), EmptyState, PackageDetail (shared by modal and page),
│                  PackageDetailModal, PhotoGallery, SlabChips, ItineraryTimeline, InclusionLists
├── departures/    DeparturesSection, DepartureCard
├── why-us/        WhyUsSection, StatCard (CountUp), TrustPoint
├── how-it-works/  StepsSection, StepCard
├── reviews/       ReviewsSection, ReviewCard
├── faq/           FaqSection, FaqItem (button + region, aria-expanded)
├── gallery/       GallerySection, GalleryTile
├── about/         AboutSection, FactCard, TeamGrid, TeamCard
├── blog/          BlogSection, CategoryChips, PostCard, PostBody (renders server-sanitised HTML)
├── newsletter/    NewsletterCard
├── contact/       ContactSection, ContactDetails, InquiryForm
├── footer/        SiteFooter (logo, section links, contact, legal links)
├── booking/       BookingModal, StepIndicator, PackageStep, TravellersStep, TravellerCard,
│                  ReviewStep (Phase 2 stops here; payment is Phase 3)
├── auth/          AuthModal (sign in · register), useCustomerSession
└── assistant/     ChatAssistant (answers from live content), FloatingActions (back-to-top, WhatsApp)

state/             trip-store (destination, date, travellers, budget), ui-store (open modal, selected
                   package, menu), booking-store — every counter change uses a functional updater
lib/
├── api/           client (fetch + cache tags), types (API DTOs), content (getHomeContent, …)
├── filter-packages.ts   ONE function → { cards, count }; the counter and the grid both read it
├── whatsapp.ts    wa.me link builder (number from settings; never includes passport data)
└── host-routing.ts
messages/{bn,en}.json    all section copy
```

Shared packages:

```
packages/format/        + formatDate() with our own Bangla/English month tables (identical on server,
                        client and the PHP twin — Intl output varies between ICU versions)
packages/pricing/       slabRate(), DEFAULT_SLABS, quoteBooking() → line items and totals;
                        fixtures.json for the PHP twin that Phase 3 needs
packages/content-seed/  bilingual seed data: packages, posts, team, slabs, add-ons, settings
packages/tokens/        unchanged
```

`@bhabaghure/pricing` is written once and used by the package cards, the detail modal and the booking
form. The server repeats the same calculation in Phase 3, when it creates the booking, and is held to
the same fixtures.

---

## 3. Component structure — CMS in the admin (`admin/src`)

Phase 5 builds the full admin shell. Phase 2 adds only what editing needs: login, a small shell with a
Website group, and three editors. The prototype has no editor screens — its "Website CMS & SEO" screen
is a block/SEO overview — so these use the admin's visual language (tables, form controls, cards) and
will need your review.

```
app/          router, AuthProvider (staff JWT + refresh), RequireAuth, CmsShell
features/
├── auth/            LoginPage
├── cms/packages/    PackageList, PackageEditor (bn/en tabs), ItineraryEditor (add, reorder, remove days),
│                    InclusionsEditor, PackagePhotos (upload, reorder, cover, credit, alt text)
├── cms/blog/        PostList, PostEditor, RichTextEditor (TipTap; bn and en bodies)
├── cms/team/        TeamList, TeamMemberEditor (square photo crop)
└── cms/media/       ImageUploader (drag and drop, progress, type/size validation), MediaPicker
lib/api/             typed client
```

Permissions: packages need `packages.manage` (Admin, Tour operator). Blog and team need `cms.manage`
(Admin). Super admin can do everything.

---

## 4. Data model additions (on top of the Phase 1 schema)

| Table | Purpose / key columns |
|---|---|
| `media` | Uploaded images: `disk`, `path`, `mime`, `width`, `height`, `bytes`, `alt_bn/en`, `credit`, `credit_url`, `uploaded_by_staff_id`. Server re-encodes every upload, strips EXIF, and caps size at 2400px |
| `package_images` | `tour_package_id`, `media_id`, `sort_order`, `is_cover` |
| `tour_packages` (+columns) | `includes_airfare` (bool, null = "ask us"), `group_mode` (`group` · `any`), `min_pax` null, `departure_mode` (`regular` · `any_date` · `on_request`), `seo_title_bn/en`, `seo_description_bn/en` |
| `pricing_slabs` | `min_pax`, `discount_percent` — seeded 1→0, 3→3, 4→6, 6→9, 10→12. The admin Pricing screen edits these same rows later |
| `addons` | `code`, `name_bn/en`, `price`, `unit` (`per_person` · `per_booking`), `is_active` |
| `blog_categories` | `slug`, `name_bn/en`, `tone` (`blue` · `purple` · `orange` — a token name, never a hex) |
| `blog_posts` | `slug`, `blog_category_id`, `title_bn/en`, `excerpt_bn/en`, `body_bn/en` (sanitised HTML), `author_bn/en`, `reading_minutes` (computed, overridable), `status`, `published_at`, `created_by_staff_id`, soft deletes |
| `team_members` | `name_bn/en`, `role_bn/en`, `employee_code`, `photo_media_id`, `sort_order`, `is_visible`, `staff_id` null |
| `site_settings` | key → JSON: contact details, opening hours, social links, service charge/VAT rate, single-room supplement, hero video, social stats |
| `inquiries` | `type` (`contact` · `air_quote`), `name`, `phone`, `email`, `tour_package_id`, `pax`, `details` JSON (route, dates, class), `locale`, `customer_id`, `status`, `assigned_staff_id` |
| `newsletter_subscribers` | `email` unique, `locale`, `status`, `unsubscribe_token`, `subscribed_at`, `unsubscribed_at` |
| `reviews` | `quote_bn/en`, `reviewer_name`, `trip_label_bn/en`, `rating` (1–5), `travelled_on`, `tour_package_id` null, `is_published`, `sort_order` |
| `gallery_items` | `kind` (`reel` · `photo`), `media_id` (thumbnail), `url` (Facebook post), `caption_bn/en`, `view_count` null, `is_published`, `sort_order` |

Rich text is sanitised on the server when it's saved, against an allow-list (`p h2 h3 strong em a ul
ol li blockquote br`). The site renders only already-sanitised HTML.

---

## 5. API surface

**Public (read, cached):** `GET /api/v1/public/packages`, `/packages/{slug}`, `/destinations`,
`/departures`, `/posts?category=`, `/posts/{slug}`, `/team`, `/settings` (includes slabs, supplement,
service charge and add-ons).

**Public (write, rate-limited, honeypot field):** `POST /api/v1/public/inquiries`,
`/air-quotes`, `/newsletter`; `GET /newsletter/unsubscribe/{token}`. Plus customer register/login
from Phase 1.

**CMS (staff JWT + permission):** `/api/v1/admin/packages` CRUD (itinerary and inclusions saved
with the package), `/admin/packages/{id}/images`, `/admin/posts`, `/admin/team`,
`POST /admin/media`. Every save triggers the website's revalidation hook.

---

## 6. Decisions from your Phase 2 brief

(The copies of `README.md` and `whatsapp-config.js` on this machine still show the original
01:05 versions, without the "Resolved ambiguities" section. These decisions are taken from your message.)

- **Group slab: the website's five tiers are authoritative.** 1–2 pax list price; 3 −3%; 4–5 −6%;
  6–9 −9%; 10+ −12%. A single traveller pays list price. The +12% belongs to the single-room choice
  at booking, not the slab. One function serves the cards, the detail modal and the booking form.
- **Reminder timings:** documents pending 7 days before departure; pre-trip 48h before; departure
  day 06:00; review request 2 days after return.
- **Admin dark theme:** its own palette. The wallet's purple palette stays separate.
- **WhatsApp endpoint:** valid staff session plus rate limiting — Phase 4.

---

## 7. Defaults I will apply unless you say otherwise

**Behaviour**
1. **Header nav:** the prototype's 8 items — Services, Packages, Departures, About, News, Book, FAQ,
   Gallery. The README says "six section links" but doesn't say which six.
2. **Header look:** the prototype's translucent white (`.92`, blur) with a `#EDE5D9` border. The README
   says solid `#fff` with `#EAE3D8`.
3. **Package grid:** the prototype's `auto-fill, minmax(min(100%, 280px), 1fr)`. The README says 300px.
4. **One destination filter.** The search select and the region chips are the same state.
5. **Budget bands test the price the card actually shows** (after the slab for the current traveller
   count). Bands don't overlap: ≤ ৳15,000 · ৳15,001–30,000 · ৳30,001+.
6. **Travel date doesn't filter** — most packages run on any date. It pre-fills the booking form, and
   the counter never claims it filtered anything.
7. **Result counter:** built from the same filtered list as the cards, so it cannot disagree.
8. **Travellers carry through:** search → card price → detail modal (opens at the search count) →
   booking form.
9. **Count-up** starts when the stats scroll into view (README). The prototype starts it on page load.
10. **Reveal:** 20px, 0.6s, 70ms stagger, 6% bottom margin, threshold 0.1. Off under reduced motion.
11. **Passport warning:** shown when expiry falls within 6 months of *the chosen departure date*. The
    prototype hardcodes April 2027.
12. **Add-ons:** none pre-selected. The prototype pre-ticks travel insurance, a paid extra the customer
    didn't choose. Prices come from the `addons` table.
13. **Booking stops at review.** The pay button is disabled with a short note, next to a WhatsApp
    button that sends package, date and traveller count only. Choosing a passport scan shows the file
    name, but nothing uploads and there is no OCR until Phase 3.
14. **Sign in / register** use the Phase 1 customer endpoints. The OTP link stays hidden until an SMS
    provider exists (the prototype pretends a code was sent).
15. **Chat assistant:** rule-based, answering from live packages and FAQ copy. The prototype's replies
    hardcode prices, including Maldives, Sri Lanka and Kashmir packages that aren't in the catalogue.
16. **Departures** come from `package_departures`, and the section hides when there are none. "Guaranteed
    departure" only shows when a departure is marked guaranteed.
17. **Stats:** package and destination counts are derived from the database. Reel views and "100%
    Bangla support" live in settings.

**Content**

18. **Package seed content:** the prototype's bilingual itineraries and inclusions (it has Bangla
    translations of all six), with WordPress codes, slugs and prices. **Thai 02 stays a draft** — its
    itinerary in the prototype was written for the design, not collected from WordPress.
19. **Seeded blog posts** (4) and **team members** (2) are taken verbatim from the prototype. The
    team card's "duplicate this card to add members" note was design-tool help and isn't shipped.
20. **Hero video:** committed as `web/public/media/hero.mp4` (5.4 MB) with the poster. It's not
    autoplayed on Save-Data connections or with reduced motion. Ask the client for a compressed cut —
    5.4 MB is heavy on mobile data.
21. **Footer:** follows the README (logo, links, contact) rather than the prototype's logo-and-copyright
    only. It includes slots for terms, privacy and refund policy pages, which SSLCommerz normally
    requires before approving a merchant.
22. **PWA:** manifest and icons in Phase 2. The service worker comes later — the handoff `sw.js`
    precaches `assets/logo.jpg`, which doesn't exist, so installation would fail.
23. **Digits in identifiers:** phone and licence numbers get Bengali digits in Bangla mode (as in the
    prototype) but never thousands grouping. Booking references stay Latin.
24. **Company address** (from the invoice): AMENA VILLA, 213/5, Lift-08, Flat-8C, 60 Feet Road,
    Agargaon, Dhaka 1207 — used in the contact block and search-engine structured data.

---

## 8. Needs the client before launch

- Real reviews. The prototype's are illustrative: the names are the admin's sample customers, and
  one trip is dated October 2026.
- Real group departures with seat counts.
- Package and gallery photos (currently Pexels/Unsplash placeholders).
- Add-on prices, the 2% service charge / VAT line, and the cancellation policy stated in the FAQ
  (21 days).
- Terms, privacy and refund policy text.
- Thai 02 itinerary and inclusions.
- Old WordPress URLs (`bhabaghureholidays.com/trip/…`) → 301 redirects to the new package pages,
  set on the old domain's host.

---

## 9. Build notes

### Built

- **All sections, in the agreed order**, rendered on the server with client islands where they
  interact. Standalone pages at `/packages/<slug>` and `/blog/<slug>`, plus `sitemap.xml`,
  `robots.txt` and schema.org data (TravelAgency, TouristTrip, BlogPosting).
- **Package detail modal** backed by the URL (push on open, back button closes it), **booking modal**
  through review, **sign-in / register modal**, **chat assistant** answering from live content,
  floating WhatsApp and back-to-top buttons, and the scroll progress bar.
- **`@bhabaghure/pricing`** (the slab and booking calculation, with fixtures) and
  **`@bhabaghure/content-seed`** (bilingual seed content generated from the design and WordPress
  data; WordPress prices cross-checked for all six packages).
- **`POST /api/revalidate`**, ready for the Laravel API to call after a CMS save.

### Verified

| Check | How |
|---|---|
| Header 73px at ≥900px in both languages; 120px below; no sideways scroll at 1280 / 1000 / 900 / 899 / 390px | Playwright measurement + e2e |
| Result counter always equals the number of cards | unit test over every filter combination + e2e |
| Eight stepper clicks in one tick all count; cards reprice to the 10+ tier | e2e |
| Card → modal at `/en/packages/<slug>`; back closes it; direct visit renders the page | e2e |
| Slab chips and group total (4 × ৳70,500 = ৳2,82,000); single-room note at 1 traveller | e2e |
| Booking reaches review with 1,50,000 + 3,000 = 1,53,000; pay button disabled | e2e |
| Bengali digits in Bangla, Latin in English; `/bn/…` → 308 to the unprefixed URL | e2e |
| Package photos load through the image optimiser | e2e |
| Visual match with the prototype at 1280px and 390px, Bangla and English | full-page captures compared side by side |

Commands: `npm test` (29 unit tests) · `npm run build --workspace web && npm run test:e2e --workspace web` (11 e2e).

### Deliberate differences from the prototype

- **Six header links, not eight.** Measured: eight items overflow the 73px row between 900px and
  about 1,100px (the prototype's header grows to 124px there). FAQ, gallery and "how booking works"
  are in the ☰ sheet and the footer.
- **Header 73 / 120px exactly** (brief). The prototype measures 75 / 106px.
- **Numerals everywhere.** Duration pills (`৭N/৮D`), service badges (`০১`) and was-prices use
  Bengali digits in Bangla — the prototype left some of these in Latin digits.
- **Count-up starts on scroll-in** (README), not on page load.
- **No add-on pre-selected; passport warning uses the chosen travel date; departures, reviews and
  gallery hidden when empty; chat assistant quotes only live packages** (§7 items 11–16).
- **Accessibility additions:** skip link, focus trap and Escape in modals, labelled steppers and
  tabs, `aria-expanded` on the FAQ and menu, and invalid buttons dimmed but still clickable so they
  can reveal their errors.

### Remaining for Phase 2

1. ~~Composer; schema approval~~ — done (Docker still not installed; a project-local MySQL is used).
2. ~~Laravel scaffold, migrations, JWT auth, seeders~~ — done.
3. ~~Public read and form endpoints~~ — done.
4. ~~CMS endpoints with image processing; admin editors~~ — done.
5. ~~Switch the website to `CONTENT_SOURCE=api`~~ — done; website e2e runs on live data.

Handoff files: re-synced on 2026-09-13 and checked against what was built — the slab tiers, notification
timings and admin dark palette all match. One contradiction remains: the admin prototype's Pricing screen
still shows the old rules (`Bhabaghure Admin.dc.html` lines 1877 and 1881: group slab 4+ −4%, 10+ −8%, and
"single supplement +12% for solo travellers"). The README's five tiers are what the API and website use.
