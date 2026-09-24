# Group tour gallery: photos of travellers on the home page (2026-09-24)

**Asked:** a section of its own showing photos of the agency's guests on trips at home and abroad. The photos move on by
themselves (a slideshow), and visitors can also go to the next or previous photo themselves. The client sent eleven
group photos for it.

**Decided with the client (2026-09-24), the recommended answer each time:** one large photo at a time; after "What
travellers say"; captions with the trip, the month and a link to the tour; the photos put on the live site at once.

## 1. What exists

- **"Stories from the road"** (`#gallery`, Admin → Website → Gallery) is the Facebook section: embedded reels and photo
  tiles that open the post on Facebook. It stays as it is. The new section is separate.
- **The offer banners** (`docs/offer-banners.md`) already have a slideshow with the behaviour wanted here: it moves by
  itself, waits while someone points at it or uses it or the tab is hidden, stays still for visitors who ask for reduced
  motion, and can be swiped. The new section shares that logic rather than copying it.
- **The media library** re-encodes every upload to WebP and drops the file's metadata (a phone photo's GPS location
  with it), in sizes up to 2400 px wide. The photos sent carry no date or location anyway (WhatsApp/Facebook copies).

## 2. Design (the recommended answers; §4 asks)

**Website:** a section headed *গ্রুপ ট্যুর গ্যালারি* / *Group tour gallery* (the client's own words, Banglish as
agreed), after "What travellers say".

- One photo at a time in a page-wide rounded card, 16:9 on computers and 4:3 on phones.
- **Nobody is cut out:** the whole photo always shows. A photo of another shape, such as an upright phone photo, sits
  on a soft, blurred copy of itself instead of being cropped.
- A caption at the foot of the photo: the trip and, when known, the month (*Mustang, Nepal · September 2026*), and
  "See this tour →" when the photo is linked to a package.
- Previous/next arrows on every screen (on phones too, as asked), a count (*3 / 10*) that still works with fifty photos,
  and swiping. It moves on every 5 seconds.
- Hidden until a photo is published; photos load only as the section nears the screen.

**Admin → Website → Group tour photos** (`cms.manage`): the usual published list — the picture from the media library,
the caption in both languages, an optional month, an optional package to link to; publish, unpublish and reorder. A
photo can't be published without its picture.

**API:** table `tour_photos` (picture, captions, month, package, status, order), `GET /public/tour-photos`, a
`tour-photos` cache tag in the four places a tag lives, and the media library refusing to delete a picture the gallery
still shows — the same shape as the offer banners and airline partners.

**Customer photos stay out of the repository.** The repo is public: the photos go only into the live media library.
Tests use the e2e test picture.

## 3. The photos sent on 2026-09-24

Captions are what the photos show, as far as I can tell; the office can correct any of them in the admin. "See this
tour" goes to a live package that covers the place.

| # | Shows | Caption | Tour link |
| --- | --- | --- | --- |
| 1 | Group of 13 with the Bhabaghure banner, snow peaks behind | Mustang, Nepal · মুস্তাং, নেপাল | Nepal Mustang Adventure |
| 2 | Couple on a longtail boat under limestone cliffs | Phi Phi Islands, Thailand · ফি ফি আইল্যান্ড, থাইল্যান্ড | Amazing Thailand |
| 3 | Group of 12 above a valley in cloud, a monastery on the ridge | Mustang, Nepal · মুস্তাং, নেপাল | Nepal Mustang Adventure |
| 4 | Four on a floating walkway in a green lagoon | Pileh Lagoon, Phi Phi, Thailand · পিলেহ লেগুন, ফি ফি, থাইল্যান্ড | Amazing Thailand |
| 5 | Group of 11 in welcome scarves at the airport | Arrival in Kathmandu, Nepal · কাঠমান্ডুতে পৌঁছে, নেপাল | — |
| 6 | Family of three on a seaside deck | A family holiday by the sea · সমুদ্রের ধারে পারিবারিক ছুটি | — |
| 7 | Group of 10 on the road at Hotel Royal Marpha | Marpha, Mustang, Nepal · মার্ফা, মুস্তাং, নেপাল | Nepal Mustang Adventure |
| 8 | Airport-bus selfie before boarding | Setting off from Dhaka · ঢাকা থেকে যাত্রা শুরু | — |
| 9 | Group of 9 in welcome scarves at the airport | Arrival in Kathmandu, Nepal · কাঠমান্ডুতে পৌঁছে, নেপাল | — |
| 10 | Large group in welcome scarves at Hotel Black Diamond | Kathmandu, Nepal · কাঠমান্ডু, নেপাল | — |

- Two of the eleven are the same group at Hotel Black Diamond at the same moment (one wider); only the closer one, where
  faces are larger, is used.
- The family photo's place isn't certain from the picture (it may be Thailand), so its caption names no place; the office
  can add it.
- The Kathmandu and Dhaka photos link to no tour: nothing says which tour those groups were on.
- Months are left blank: nothing in the files says when each trip was.

They were entered on the live server with a one-off script through the admin's own controller (audited), from a folder
beside it that was deleted afterwards. The photos exist only in the live media library, as WebP without metadata.

## 4. How it is built

**New.**

- API: migration `2026_09_24_160000_create_tour_photos_table.php` (captions, `media_id`, `trip_month` as the month's
  first day, `tour_package_id`, status, order); model `TourPhoto`; `Admin\TourPhotoController` (the published-list
  trait: create, edit, delete, publish, unpublish, reorder; audited as `cms.tour_photo.*`, morph `tour_photo`);
  `GET /public/tour-photos`; `lang/{en,bn}/cms.php` → `publish_requirements.photo`.
- Admin: `features/cms/tour-photos/TourPhotosPage.tsx`, *Website → Group tour photos* (`/tour-photos`, `cms.manage`).
- Website: `features/tour-photos/TourPhotosSection.tsx` (`id="tour-photos"`, after the reviews) and
  `TourPhotoSlideshow.tsx`; `components/ui/Slideshow.tsx` — `useSlideshow` and `SlideArrow`, taken out of the offer
  banners' slideshow, which now uses them too (same behaviour). Slide labels get their numbers formatted, so the Bangla
  site reads "ছবি ১/৩" — the offer banners' labels had Latin digits ("অফার 1/3") and now match.

**Changed.** The `tour-photos` cache tag in all four places (`RevalidateWebsite`, the website's `/api/revalidate`,
`deploy.sh revalidate_all`, `smoke.sh`, which also checks the section once a photo is published); the website loads the
list with an empty fallback (deploy builds the site before the API serves the route); the media library refuses to
delete a picture the gallery shows; `month` in the website's formatters.

## 5. Tests

- **API** `TourPhotosTest`: a picture is needed to publish, then the photo reaches the website with its caption, month,
  picture and tour; the tour link shows only while the package is published (and a deleted package can't be chosen);
  order and unpublishing; captions required and the month a real month (and kept when an edit leaves it out); a
  picture in use can't be deleted; only website staff. `CmsPermissionsTest` has the path.
- **Admin e2e** `cms.spec.ts`: publishing is refused without a photo; with one, the photo reaches the public API with its
  month and tour.
- **Website e2e** `live-data.spec.ts`: after "How it works" (the e2e site has no reviews) and before the FAQ; caption,
  month and tour link; the photo shown whole (`object-fit: contain`); it moves on by itself; under the pointer it holds
  still and the arrows go either way round the ends; the Bangla heading, month and count. The offer banners' test runs
  unchanged against the shared slideshow code.
