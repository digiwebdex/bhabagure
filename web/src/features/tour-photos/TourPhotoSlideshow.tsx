'use client';

import { useTranslations } from 'next-intl';

import { SlideArrow, useSlideshow } from '@/components/ui/Slideshow';
import { Link } from '@/i18n/navigation';
import type { SiteViews } from '@/lib/content/views';
import { useFormatters } from '@/lib/use-formatters';

const EVERY_MS = 5000;

/**
 * The group tour gallery's slideshow (docs/group-tour-gallery.md): one photo at a time, always whole. A photo of another
 * shape, such as an upright phone photo, sits on a soft, blurred copy of itself rather than being cropped, so nobody in
 * a group is cut off. The caption says which trip it was, and when if known, with "See this tour" while its package is
 * on the website. Previous/next on every screen (the client asked for them), a count, swiping, and every five seconds on
 * its own, waiting as the offer banners do (useSlideshow).
 */
export function TourPhotoSlideshow({ photos }: { photos: SiteViews['tourPhotos'] }) {
  const t = useTranslations('tourPhotos');
  const f = useFormatters();
  const { track, current, step, holdProps } = useSlideshow(photos.length, EVERY_MS);

  return (
    <div aria-roledescription="carousel" aria-label={t('label')} className="relative" {...holdProps}>
      <ul ref={track} className="scrollbar-none m-0 flex snap-x snap-mandatory list-none gap-0 overflow-x-auto rounded-fluid p-0" data-testid="tour-photos">
        {photos.map((photo, index) => (
          <li
            key={`${index}:${photo.image.url}`}
            className="w-full shrink-0 snap-center"
            aria-roledescription="slide"
            aria-label={t('slide', { n: f.number(index + 1), total: f.number(photos.length) })}
          >
            <figure className="relative m-0 aspect-4/3 overflow-hidden rounded-fluid bg-ink-deep sm:aspect-16/9">
              {/* Plain images from the sizes the API already made, not next/image: optimising these large photos on the
                  website's server held it over its memory limit until it stopped answering (2026-09-24). The same photo,
                  blurred and dimmed, fills whatever the photo itself doesn't. */}
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={photo.blurUrl} alt="" aria-hidden="true" loading="lazy" decoding="async" className="absolute inset-0 size-full scale-110 object-cover opacity-60 blur-2xl" />
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={photo.image.url}
                srcSet={photo.srcSet ?? undefined}
                sizes="(min-width: 1200px) 1140px, 100vw"
                alt={t('alt', { caption: photo.caption })}
                loading="lazy"
                decoding="async"
                className="absolute inset-0 size-full object-contain"
              />
              <figcaption className="absolute inset-x-0 bottom-0 flex flex-wrap items-end justify-between gap-x-4 gap-y-2 bg-linear-to-t from-black/75 via-black/35 to-transparent px-4 pt-12 pb-3.5 text-white sm:px-6 sm:pb-5">
                <span className="text-15 font-semibold sm:text-18">
                  {photo.caption}
                  {photo.month ? <span className="font-normal text-white/80"> · {f.month(photo.month)}</span> : null}
                </span>
                {photo.packageSlug ? (
                  <Link
                    href={`/packages/${photo.packageSlug}`}
                    aria-label={t('seeTourLabel', { title: photo.packageTitle ?? photo.caption })}
                    className="rounded-pill bg-white/15 px-3.5 py-1.5 text-13 font-semibold text-white backdrop-blur-sm transition hover:bg-white/25 hover:text-white"
                  >
                    {t('seeTour')} →
                  </Link>
                ) : null}
              </figcaption>
            </figure>
          </li>
        ))}
      </ul>

      {photos.length > 1 ? (
        <>
          <SlideArrow side="left" label={t('previous')} onClick={() => step(-1)} onPhones />
          <SlideArrow side="right" label={t('next')} onClick={() => step(1)} onPhones />
          {/* Where the visitor is; each slide's own label says it to a screen reader. */}
          <span aria-hidden="true" data-testid="tour-photos-count" className="absolute top-3 right-3 rounded-pill bg-black/55 px-2.5 py-1 font-display text-12 font-semibold text-white backdrop-blur-sm">
            {f.number(current + 1)} / {f.number(photos.length)}
          </span>
        </>
      ) : null}
    </div>
  );
}
