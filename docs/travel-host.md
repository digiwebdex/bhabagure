# Travel host: "Travel with Shishir Deb" on the home page

**Asked (2026-09-17).** The client sent Shishir Deb's Facebook and YouTube links. He is not the company's Facebook page
(that stays facebook.com/bhabaghureholidays); he is a well-known travel vlogger and the traveller behind the company,
and the client wants visitors to see him: a section of its own, with thumbnail cards, animation, easy to see and
highlighted.

**Decided with the client (2026-09-17):**
- Described as *the traveller behind Bhabaghure Holidays*, with no formal title.
- High up on the home page, right after Services. **Moved 2026-09-18 at the client's word:** between the About section
  and the news, so the page builds up to him rather than opening with him.
- The videos under the cards are hand-picked in the admin, not his newest uploads: several of those are sponsored
  (a telecom, a phone brand) and do not belong on the agency's page unless someone chooses them.
- His profile photos and follower counts are shown; the counts are edited in the admin.

## Website

`web/src/features/creator/CreatorSection.tsx`, between the About section and the news, `id="travel-host"`. Hidden until
a profile with at least one link is saved.

- **On navy**, with two soft brand-colour glows, so it stands out from the pale sections either side of it.
- **Heading:** "Travel with {name}" / "ভ্রমণের সঙ্গী {name}", then *The traveller behind Bhabaghure Holidays* and the
  short bio.
- **Two cards, one each for Facebook and YouTube.** Each card is a single link to the profile, in a new tab. It shows a
  cover (or the brand colour without one), the round photo in a slowly turning ring, the name and the address, the
  counts, and "Follow on Facebook" / "Subscribe on YouTube". The cover is 16:5 so a YouTube banner (about 6:1) still
  shows its middle.
- **Counts** read as the platforms show them and are never overstated — rounded down: 1,107,339 → "1.1M" / "১১ লাখ",
  712,000 → "712K" / "৭.১ লাখ" (`formatAudience` in `packages/format`). They count up from zero as the card scrolls in.
- **Videos:** YouTube's own still with a play button and the title; one row that scrolls sideways on a phone, two then
  four across on wider screens. Each opens the video on YouTube in a new tab: nothing is embedded, so the home page
  loads no YouTube player or cookies. "More videos on YouTube" links to the channel.
- **Motion:** cards and videos rise in with the site's scroll reveal, lift on hover, and the photo ring turns. Visitors
  who ask for reduced motion get none of it (`data-reveal`, `CountUp` and `motion-safe:animate-ring-spin` all check).

## Admin → Website → Travel host (`/travel-host`, `cms.manage`)

- **Profile:** name and a short bio in both languages; a Facebook card (page link, followers, photo, cover) and a
  YouTube card (channel link, subscribers, number of videos, photo, cover). Saving needs a name and at least one link.
  Links pasted from a phone's Share button lose their tracking (`?si=`, `?mibextid=`); a Facebook profile known only by
  number keeps its `?id=`. Photos come from the media library, which then refuses to delete them while they are shown.
- **Videos:** the usual website list — paste any YouTube link (address bar, youtu.be, Shorts), give a title in either
  language, publish, reorder. The same video cannot be added twice. The thumbnail shows as soon as the link is pasted.

## API

- Profile: site setting `creator` (`App\Support\CreatorProfile` holds its shape), edited through
  `GET`/`PUT /admin/creator` (`CreatorProfileController`). Audited as `cms.creator.updated`.
- Videos: table `creator_videos` (`youtube_id` unique, titles, status, order), model `CreatorVideo`,
  `CreatorVideoController` on the published-list routes under `/admin/creator-videos`. Audited as `cms.creator_video.*`.
- Public: `GET /public/creator` → `{ profile, videos }`, profile null until there is a link. Photos come as images
  (avatar at the thumb size, cover at detail size); thumbnails are `i.ytimg.com/vi/<id>/hqdefault.jpg`.
- Saves refresh the website through the new `creator` cache tag, added everywhere the tags are listed:
  `RevalidateWebsite`, the website's `/api/revalidate`, `deploy.sh revalidate_all` and `smoke.sh`. The website loads the
  endpoint with an empty fallback, because a deploy builds the website before the API has the route.
- `next.config.ts` allows `i.ytimg.com/vi/**` through the image optimizer.

## Content on live (2026-09-17)

- **Photos.** Facebook shows automated visitors a grey placeholder instead of the profile photo, so both cards use his
  YouTube channel photo: a face-centred square for the round photo, and a wide crop of it as the Facebook card's cover.
  The YouTube card's cover is his channel banner. All three are in the media library.
- **Counts as of 2026-09-17:** 1,107,339 Facebook followers; 712K subscribers and 295 videos on YouTube.
- **Videos to start with:** four travel videos tied to countries the agency sells visas for — Thailand, Singapore and
  Malaysia for 1.2 lakh taka; Mount Bromo, Indonesia; the Philippines parts 1 and 2. Change them in the admin.

## Tests

- API `CreatorTest`: hidden until a link is saved; validation; saved profile served with photos and without tracking;
  a numbered Facebook profile keeps its id; a shown photo cannot be deleted; every shape of video link; duplicates;
  publish and order; permissions. `CmsPermissionsTest` covers both new paths.
- `packages/format`: `formatAudience` in both languages, rounding down at every unit.
- Website e2e `live-data.spec.ts`: saved in the CMS → appears between About and the news with both cards, counts, the video and its
  thumbnail through the optimizer, in English and Bangla.
- Admin e2e `cms.spec.ts`: a link is required; the video's thumbnail previews; publishing reaches the public API.
