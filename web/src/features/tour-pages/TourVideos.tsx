'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import type { TourVideoView } from '@/lib/content/views';
import { facebookVideoEmbedUrl } from '@/lib/links';

/** Facebook's reel player at the size its embed uses (9:16), as on the home page's "Stories from the road". */
const REEL_WIDTH = 260;
const REEL_HEIGHT = 462;

const youtubeEmbed = (id: string) => `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&playsinline=1`;

/**
 * Guests' video feedback on a landing page (docs/tour-landing-pages.md §9): a row of portrait 9:16 cards that plays in
 * the page, like the home page's "Stories from the road" — four or five across on a wide screen, swiped on a phone. A
 * Facebook video is Facebook's own player, loaded as the row nears the screen; a YouTube one is its still and a play
 * button until clicked — nothing of YouTube's loads before — then plays in its card from youtube-nocookie.com.
 */
export function GuestVideos({ videos }: { videos: TourVideoView[] }) {
  const t = useTranslations('tourPage');

  return (
    <ul className="-mx-section-x m-0 flex list-none snap-x scroll-px-section-x gap-3.5 overflow-x-auto px-section-x pb-2" data-testid="tour-guest-video-list">
      {videos.map((video, index) => {
        const title = video.title ?? t('videoUntitled');
        return (
          <li key={`${index}-${video.url}`} className="flex w-65 shrink-0 snap-start flex-col gap-2">
            {video.provider === 'youtube' && video.youtubeId ? (
              <YouTubeStill video={video} youtubeId={video.youtubeId} title={title} className="h-115.5 w-65 rounded-18" />
            ) : (
              <iframe
                src={facebookVideoEmbedUrl(video.url, REEL_WIDTH, REEL_HEIGHT)}
                width={REEL_WIDTH}
                height={REEL_HEIGHT}
                loading="lazy"
                title={title}
                allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
                allowFullScreen
                className="h-115.5 w-65 overflow-hidden rounded-18 border-0 bg-image-placeholder"
                data-testid="tour-video-facebook"
              />
            )}
            {video.title ? <span className="line-clamp-2 px-1 text-14 leading-1.45 font-semibold">{video.title}</span> : null}
          </li>
        );
      })}
    </ul>
  );
}

/**
 * The travel vlogs (§9): one large player, centred, up to 960 px wide (the page's width on a phone), with the first vlog;
 * the others beneath as small cards that load into it when clicked. Nothing from YouTube or Facebook loads before a
 * click.
 */
export function VlogPlayer({ videos }: { videos: TourVideoView[] }) {
  const t = useTranslations('tourPage');
  const [current, setCurrent] = useState(0);
  const [playing, setPlaying] = useState(false);
  const video = videos[current] ?? videos[0];
  const title = video.title ?? t('videoUntitled');

  return (
    <div className="mx-auto flex w-full max-w-240 flex-col gap-4" data-testid="tour-vlog-player">
      <div className="relative aspect-video w-full overflow-hidden rounded-22 bg-ink-deep shadow-card">
        {playing ? (
          <iframe
            key={video.url}
            src={video.provider === 'youtube' && video.youtubeId ? youtubeEmbed(video.youtubeId) : facebookVideoEmbedUrl(video.url, 960, 540)}
            title={title}
            allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share; fullscreen"
            allowFullScreen
            referrerPolicy="strict-origin-when-cross-origin"
            className="absolute inset-0 size-full border-0"
            data-testid="tour-video-player"
          />
        ) : (
          <button
            type="button"
            onClick={() => setPlaying(true)}
            aria-label={t('playVideo', { title })}
            className="group absolute inset-0 cursor-pointer border-0 bg-transparent p-0 text-left"
            data-testid="tour-video-play"
          >
            <Still video={video} />
            <span aria-hidden className="absolute inset-0 bg-linear-to-t from-ink-deep/75 via-ink-deep/10 to-transparent" />
            <PlayMark className={`size-18 sm:size-22 ${video.provider === 'youtube' ? 'bg-youtube' : 'bg-facebook'}`} />
            {video.title ? (
              <span className="absolute inset-x-0 bottom-0 p-fluid-16-22 font-display text-fluid-19-24 leading-1.3 font-bold text-white">{video.title}</span>
            ) : null}
          </button>
        )}
      </div>

      {videos.length > 1 ? (
        <ul className="m-0 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 lg:grid-cols-4" data-testid="tour-vlog-list">
          {videos.map((choice, index) => (
            <li key={`${index}-${choice.url}`}>
              <button
                type="button"
                onClick={() => {
                  setCurrent(index);
                  setPlaying(true);
                }}
                aria-current={index === current}
                aria-label={t('playVideo', { title: choice.title ?? t('videoUntitled') })}
                className="group flex w-full cursor-pointer flex-col gap-1.5 border-0 bg-transparent p-0 text-left"
                data-testid="tour-vlog-choice"
              >
                <span className={`relative block aspect-video w-full overflow-hidden rounded-12 bg-ink-deep ${index === current ? 'ring-3 ring-orange' : 'ring-1 ring-hairline'}`}>
                  <Still video={choice} />
                  <PlayMark className={`size-9 ${choice.provider === 'youtube' ? 'bg-youtube' : 'bg-facebook'}`} />
                </span>
                {choice.title ? <span className="line-clamp-2 text-13 leading-1.4 font-semibold text-ink">{choice.title}</span> : null}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

function YouTubeStill({ video, youtubeId, title, className }: { video: TourVideoView; youtubeId: string; title: string; className: string }) {
  const t = useTranslations('tourPage');
  const [playing, setPlaying] = useState(false);

  return (
    <div className={`relative overflow-hidden bg-ink-deep shadow-card ${className}`}>
      {playing ? (
        <iframe
          src={youtubeEmbed(youtubeId)}
          title={title}
          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
          className="absolute inset-0 size-full border-0"
          data-testid="tour-video-player"
        />
      ) : (
        <button type="button" onClick={() => setPlaying(true)} aria-label={t('playVideo', { title })} className="group absolute inset-0 cursor-pointer border-0 bg-transparent p-0" data-testid="tour-video-play">
          <Still video={video} />
          <span aria-hidden className="absolute inset-0 bg-linear-to-t from-ink-deep/60 via-transparent to-transparent" />
          <PlayMark className="size-14 bg-youtube" />
        </button>
      )}
    </div>
  );
}

/** Staff's picture, else YouTube's own still, else a plain card in Facebook's blue. */
function Still({ video }: { video: TourVideoView }) {
  return video.thumbnail ? (
    // eslint-disable-next-line @next/next/no-img-element -- YouTube's still or the API's own card size
    <img src={video.thumbnail} alt="" loading="lazy" decoding="async" className="absolute inset-0 size-full object-cover transition-transform duration-500 ease-lift group-hover:scale-104" />
  ) : (
    <span aria-hidden className="absolute inset-0 bg-linear-to-br from-facebook to-ink-deep" />
  );
}

function PlayMark({ className }: { className: string }) {
  return (
    <span aria-hidden className={`absolute top-1/2 left-1/2 flex -translate-1/2 items-center justify-center rounded-pill text-white shadow-raised transition-transform duration-300 ease-lift group-hover:scale-110 ${className}`}>
      <svg viewBox="0 0 24 24" className="ml-[8%] size-[42%] fill-current">
        <path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.02-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" />
      </svg>
    </span>
  );
}
