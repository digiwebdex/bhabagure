# Home page video (2026-10-01)

**Asked:** in the admin, replace the home page's hero video by uploading an MP4/WebM or giving a video URL (cloud
storage); the website binds the video from the settings instead of a hard-coded path, and plays it as before — muted,
looping, inline, starting on its own.

**Decided (the client's answers, 2026-10-01):**
- Uploads up to **20 MB**. The card advises short (10–20 s) and under 8 MB: every visitor on mobile data downloads it.
- The **poster** (the still shown before the video plays, and instead of it on Data Saver) is **taken from the uploaded
  video** in the admin's browser. A link may come with a poster picture; without one, the hero's dark background shows
  until the video plays.
- **Only the video in use is kept:** a replaced upload and its poster are deleted (the server's disk is shared and
  nearly full). The original video ships with the website and can always be restored.

## How it works

**Admin → Site settings → Home page video** (cms.manage):
- **Now playing:** a preview of the current video and what it is: the original, an upload (name and size), or a link.
  **Use the original video** goes back to the one the website ships with.
- **Upload a video:** the file is checked (MP4 or WebM, 20 MB at most) and previewed, and its poster is taken from the
  frame one second in (halfway through a shorter clip), all before anything is sent. It then goes up in pieces
  (`HeroVideo::chunkBytes()`: 4 MB on the server, well inside its 8 MB request limit, so no server setting changes; less
  where PHP takes smaller uploads, as on a developer's machine). A piece lost to the network is sent again and never
  added twice. When the last piece is in, the file itself is checked (finfo: really an MP4 or WebM) and put on the page.
- **Use a link:** a direct https link to the video file. The preview says whether it plays in this browser; links to
  pages that show a video (YouTube, Facebook, Vimeo, TikTok, Instagram) are refused with the reason.

**Website:** the home page's hero plays `settings.hero.videoUrl` over `settings.hero.posterUrl`, else
`/media/hero.mp4` over `/media/hero-poster.jpg`. Unchanged: it starts playing only for visitors who haven't asked for
reduced motion or reduced data (Data Saver), who see the poster and download nothing more. A change shows at once (the
settings cache is refreshed on save).

## Data and API

- `site_settings.hero`, written only by `App\Services\Media\HeroVideo`: `{ source: upload|link, video, poster, name,
  bytes }`, where `video` is a path on the public disk (`hero/…`) or the link, and `poster` a path or null. The general
  settings update never writes it: its paths are files the class deletes.
- Uploaded files: `storage/app/public/hero/<random>.mp4|webm|jpg`, served by nginx from `api…/storage/hero/…` (byte
  ranges for video, cached 30 days — a new upload has a new name). Unfinished uploads wait in
  `storage/app/private/tmp/hero-video/` and are cleared a day later.
- `GET /public/settings` adds `hero: { videoUrl, posterUrl } | null` — URLs only, never the stored paths.
- Admin, under `admin/settings/hero-video`: `GET` (current video, `maxBytes`, `chunkBytes`), `POST chunks`
  (`upload` uuid, `index`, `size`, `type`, `name`, `chunk`), `POST` (`upload`, `poster`: publish the upload), `POST link`
  (`url`, `poster`), `DELETE` (the original video again). Changes are audited (`cms.hero_video.updated` / `.restored`).

## Tests

- `api/tests/Feature/HeroVideoTest`: pieces in order, a retried piece not added twice, out-of-order refused, publishing
  only a finished upload, the reassembled file identical, the public settings showing URLs only, the old files deleted
  on replace, restore; over 20 MB refused, a non-video refused and nothing kept, video pages and http links refused;
  permissions, and the general settings update refused for `hero`.
- `admin/e2e/hero-video.spec.ts`: the website's own 5.4 MB video uploaded in pieces, a YouTube link refused, a direct link
  taken, the original restored.
- `web/e2e/live-data.spec.ts`: the hero plays the chosen video — still muted, looping and inline — and the original again
  once it is restored.
