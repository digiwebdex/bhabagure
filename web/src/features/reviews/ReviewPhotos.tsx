'use client';

import { useTranslations } from 'next-intl';
import { useId, useState } from 'react';

import { Modal, ModalClose } from '@/components/ui/Modal';
import type { ReviewView } from '@/lib/content/views';
import { useFormatters } from '@/lib/use-formatters';

/**
 * A review's trip photos (docs/customer-reviews.md): small thumbnails on the card; a click opens the photo larger, with
 * previous/next when there are several. Plain images of the sizes the API stored (400 px and 1600 px), never the
 * website's image optimiser: optimising photos on the site's server once held it over its memory limit (2026-09-24).
 */
export function ReviewPhotos({ photos, reviewer }: { photos: ReviewView['photos']; reviewer: string }) {
  const t = useTranslations('reviewForm');
  const tc = useTranslations('common');
  const f = useFormatters();
  const titleId = useId();
  const [shown, setShown] = useState<number | null>(null);
  if (photos.length === 0) return null;
  const photo = shown === null ? null : photos[shown];

  return (
    <>
      <ul className="m-0 flex list-none flex-wrap gap-2 p-0" data-testid="review-photos">
        {photos.map((item, index) => (
          <li key={item.thumb}>
            <button type="button" onClick={() => setShown(index)} aria-label={t('openPhoto', { n: f.number(index + 1), name: reviewer })} className="block cursor-pointer overflow-hidden rounded-10">
              {/* eslint-disable-next-line @next/next/no-img-element -- the API's own 400 px WebP */}
              <img src={item.thumb} alt={item.alt} loading="lazy" decoding="async" width={72} height={72} className="size-18 object-cover transition-transform duration-300 ease-lift hover:scale-105" />
            </button>
          </li>
        ))}
      </ul>
      <Modal open={photo !== null} onClose={() => setShown(null)} labelledBy={titleId} size="lg" layer="detail">
        {photo && shown !== null ? (
          <>
            <div className="flex shrink-0 items-center justify-between gap-3 border-b border-hairline px-4 py-3">
              <h2 id={titleId} className="text-15 font-semibold">
                {t('photoOf', { name: reviewer })} · {f.number(shown + 1)} / {f.number(photos.length)}
              </h2>
              <ModalClose onClick={() => setShown(null)} label={tc('close')} size="sm" />
            </div>
            <div className="relative flex min-h-0 flex-1 items-center justify-center bg-ink-deep">
              {/* eslint-disable-next-line @next/next/no-img-element -- the API's own 1600 px WebP */}
              <img src={photo.large} alt={photo.alt} decoding="async" className="max-h-[75vh] w-auto max-w-full object-contain" />
              {photos.length > 1 ? (
                <>
                  <button type="button" onClick={() => setShown((shown - 1 + photos.length) % photos.length)} aria-label={t('previousPhoto')} className="absolute top-1/2 left-3 flex size-10 -translate-y-1/2 cursor-pointer items-center justify-center rounded-pill bg-white/90 text-ink shadow-raised">
                    ‹
                  </button>
                  <button type="button" onClick={() => setShown((shown + 1) % photos.length)} aria-label={t('nextPhoto')} className="absolute top-1/2 right-3 flex size-10 -translate-y-1/2 cursor-pointer items-center justify-center rounded-pill bg-white/90 text-ink shadow-raised">
                    ›
                  </button>
                </>
              ) : null}
            </div>
          </>
        ) : null}
      </Modal>
    </>
  );
}
