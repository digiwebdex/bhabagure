'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import type { TourVideoView } from '@/lib/content/views';

/**
 * Guests' video feedback or travel vlogs on a landing page (docs/tour-landing-pages.md §9). A YouTube video is a still
 * and a play button until clicked — nothing of YouTube's player loads before, and then only from youtube-nocookie.com
 * — and plays in its card. A Facebook video opens on Facebook: its player can't be embedded without Facebook's script.
 */
export function TourVideos({ videos, testId }: { videos: TourVideoView[]; testId: string }) {
  return (
    <ul className="-mx-section-x m-0 flex list-none snap-x scroll-px-section-x gap-4 overflow-x-auto px-section-x pb-2 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 lg:grid-cols-3" data-testid={testId}>
      {videos.map((video, index) => (
        <li key={`${index}-${video.url}`} className="w-[82%] shrink-0 snap-start sm:w-auto">
          {video.provider === 'youtube' && video.youtubeId ? <YouTubeCard video={video} youtubeId={video.youtubeId} /> : <FacebookCard video={video} />}
        </li>
      ))}
    </ul>
  );
}

function YouTubeCard({ video, youtubeId }: { video: TourVideoView; youtubeId: string }) {
  const t = useTranslations('tourPage');
  const [playing, setPlaying] = useState(false);
  const title = video.title ?? t('videoUntitled');

  return (
    <figure className="m-0 flex flex-col gap-2.5">
      <div className="relative aspect-video overflow-hidden rounded-16 bg-ink-deep shadow-card">
        {playing ? (
          <iframe
            src={`https://www.youtube-nocookie.com/embed/${youtubeId}?autoplay=1&rel=0&playsinline=1`}
            title={title}
            allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
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
            className="group absolute inset-0 cursor-pointer border-0 bg-transparent p-0"
            data-testid="tour-video-play"
          >
            {video.thumbnail ? (
              // eslint-disable-next-line @next/next/no-img-element -- YouTube's still or the API's own card size
              <img src={video.thumbnail} alt="" loading="lazy" decoding="async" className="size-full object-cover transition-transform duration-500 ease-lift group-hover:scale-104" />
            ) : null}
            <span aria-hidden className="absolute inset-0 bg-linear-to-t from-ink-deep/60 via-transparent to-transparent" />
            <PlayMark className="bg-youtube" />
          </button>
        )}
      </div>
      {video.title ? <figcaption className="line-clamp-2 text-15 leading-1.45 font-semibold">{video.title}</figcaption> : null}
    </figure>
  );
}

function FacebookCard({ video }: { video: TourVideoView }) {
  const t = useTranslations('tourPage');

  return (
    <a href={video.url} target="_blank" rel="noopener noreferrer" className="group flex flex-col gap-2.5 text-ink hover:text-ink" data-testid="tour-video-facebook">
      <span className="relative block aspect-video overflow-hidden rounded-16 bg-linear-to-br from-facebook to-ink-deep shadow-card">
        {video.thumbnail ? (
          // eslint-disable-next-line @next/next/no-img-element -- the API's own card size
          <img src={video.thumbnail} alt="" loading="lazy" decoding="async" className="size-full object-cover transition-transform duration-500 ease-lift group-hover:scale-104" />
        ) : null}
        <span aria-hidden className="absolute inset-0 bg-linear-to-t from-ink-deep/60 via-transparent to-transparent" />
        <PlayMark className="bg-facebook" />
        <span className="absolute right-3 bottom-3 rounded-pill bg-white/95 px-3 py-1 text-12 font-bold text-facebook">{t('watchOnFacebook')} ↗</span>
      </span>
      {video.title ? <span className="line-clamp-2 text-15 leading-1.45 font-semibold">{video.title}</span> : null}
    </a>
  );
}

function PlayMark({ className }: { className: string }) {
  return (
    <span aria-hidden className={`absolute top-1/2 left-1/2 flex size-14 -translate-1/2 items-center justify-center rounded-pill text-white shadow-raised transition-transform duration-300 ease-lift group-hover:scale-110 ${className}`}>
      <svg viewBox="0 0 24 24" className="ml-0.5 size-6 fill-current">
        <path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.02-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" />
      </svg>
    </span>
  );
}
