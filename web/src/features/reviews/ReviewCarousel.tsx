'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react';

import { Modal, ModalClose } from '@/components/ui/Modal';
import { SlideArrow, useSlideshow } from '@/components/ui/Slideshow';
import type { ReviewView } from '@/lib/content/views';
import { initialsOf } from '@/lib/initials';
import { useFormatters } from '@/lib/use-formatters';

import { ReviewPhotos } from './ReviewPhotos';

/** Columns of cards, as the grid's own classes lay them out: one on phones, two from `sm`, three from `lg`. */
const WIDE = '(min-width: 64rem)';
const MEDIUM = '(min-width: 40rem)';

function subscribe(onChange: () => void) {
  const queries = [window.matchMedia(WIDE), window.matchMedia(MEDIUM)];
  queries.forEach((query) => query.addEventListener('change', onChange));
  return () => queries.forEach((query) => query.removeEventListener('change', onChange));
}

const columnsNow = () => (window.matchMedia(WIDE).matches ? 3 : window.matchMedia(MEDIUM).matches ? 2 : 1);

/** The server lays out three; a narrower screen re-pages once the page is live (below the fold, so nothing visibly moves). */
const useColumns = () => useSyncExternalStore(subscribe, columnsNow, () => 3);

/**
 * Travellers' reviews two rows at a time (client, 2026-10-02; docs/customer-reviews.md): six to a page on a wide screen,
 * four on a tablet, two on a phone, with previous/next, dots and swipe — nothing moves by itself, so a review never
 * slides away while it is being read. Every card is the same height: a long review stops at five lines and "Read more"
 * opens the whole of it. The home page and a package's page.
 */
export function ReviewCards({ reviews }: { reviews: ReviewView[] }) {
  const t = useTranslations('reviewForm');
  const f = useFormatters();
  const perPage = useColumns() * 2;
  const pages = Array.from({ length: Math.ceil(reviews.length / perPage) }, (_, index) => reviews.slice(index * perPage, (index + 1) * perPage));
  const { track, current, show, step } = useSlideshow(pages.length, null);
  const [open, setOpen] = useState<ReviewView | null>(null);
  const paged = pages.length > 1;

  return (
    <div role="group" aria-roledescription="carousel" aria-label={t('listLabel')} data-reveal className="flex flex-col gap-5">
      <ul ref={track} className="scrollbar-none m-0 flex list-none snap-x snap-mandatory overflow-x-auto p-0" data-testid="review-cards">
        {pages.map((page, index) => (
          <li
            key={index}
            className="w-full shrink-0 snap-start snap-always"
            aria-roledescription="slide"
            aria-label={t('page', { n: f.number(index + 1), total: f.number(pages.length) })}
          >
            {/* Two rows on every page, so each page is as tall as the others and paging never moves what is below. */}
            <div className={`grid h-full gap-4.5 sm:grid-cols-2 lg:grid-cols-3 ${paged ? 'grid-rows-2' : ''}`}>
              {page.map((review, at) => (
                <ReviewCard key={`${index * perPage + at}-${review.reviewerName}`} review={review} onOpen={() => setOpen(review)} />
              ))}
            </div>
          </li>
        ))}
      </ul>

      {paged ? (
        <div className="flex items-center justify-center gap-3" data-testid="review-pages">
          <SlideArrow inline side="left" label={t('previousPage')} onClick={() => step(-1)} />
          <div className="flex flex-wrap justify-center gap-2">
            {pages.map((page, index) => (
              <button
                key={index}
                type="button"
                aria-label={t('goToPage', { n: f.number(index + 1) })}
                aria-current={index === current}
                onClick={() => show(index)}
                className={`h-2 cursor-pointer rounded-pill border-0 transition-all duration-300 ${index === current ? 'w-6 bg-orange-deep' : 'w-2 bg-hairline-hover hover:bg-muted-label'}`}
              />
            ))}
          </div>
          <SlideArrow inline side="right" label={t('nextPage')} onClick={() => step(1)} />
        </div>
      ) : null}

      <FullReview review={open} onClose={() => setOpen(null)} />
    </div>
  );
}

function ReviewCard({ review, onOpen }: { review: ReviewView; onOpen: () => void }) {
  const t = useTranslations('reviewForm');
  const quote = useRef<HTMLQuoteElement>(null);
  const [cut, setCut] = useState(false);

  // Whether five lines hold it depends on the card's width; the observer answers at once and on every resize.
  useEffect(() => {
    const element = quote.current;
    if (!element) return;
    const observer = new ResizeObserver(() => setCut(element.scrollHeight > element.clientHeight + 1));
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  return (
    <figure className="m-0 flex min-w-0 flex-col gap-3 rounded-20 border border-hairline bg-white p-6">
      <Stars rating={review.rating} />
      <blockquote ref={quote} className="m-0 line-clamp-5 text-16 leading-1.6 text-pretty whitespace-pre-line">
        {review.quote}
      </blockquote>
      {/* Its line is kept on every card, so a card doesn't grow when the button appears. */}
      <button type="button" onClick={onOpen} className={`-mt-1.5 cursor-pointer self-start text-14 font-semibold text-blue hover:text-blue-deep ${cut ? '' : 'invisible'}`}>
        {t('readMore')}
      </button>
      <ReviewPhotos photos={review.photos} reviewer={review.reviewerName} max={3} />
      <figcaption className="mt-auto">
        <Reviewer review={review} />
      </figcaption>
    </figure>
  );
}

/** The whole review, with its photos at a size worth looking at; a photo opens full size in a new tab. */
function FullReview({ review, onClose }: { review: ReviewView | null; onClose: () => void }) {
  const t = useTranslations('reviewForm');
  const tc = useTranslations('common');
  const titleId = useId();

  return (
    <Modal open={review !== null} onClose={onClose} labelledBy={titleId} size="md" layer="detail">
      {review ? (
        <>
          <div className="flex shrink-0 items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <h2 id={titleId} className="text-16 font-semibold">
              {t('fullReview', { name: review.reviewerName })}
            </h2>
            <ModalClose onClick={onClose} label={tc('close')} size="sm" />
          </div>
          <div className="flex min-h-0 flex-col gap-4 overflow-y-auto px-5 py-5" data-testid="full-review">
            <Stars rating={review.rating} />
            <blockquote className="m-0 text-16 leading-1.65 text-pretty whitespace-pre-line">{review.quote}</blockquote>
            {review.photos.length > 0 ? (
              <ul className="m-0 grid list-none grid-cols-2 gap-2 p-0 sm:grid-cols-3">
                {review.photos.map((photo) => (
                  <li key={photo.thumb}>
                    <a href={photo.large} target="_blank" rel="noopener noreferrer" className="block overflow-hidden rounded-12">
                      {/* eslint-disable-next-line @next/next/no-img-element -- the API's own 400 px WebP */}
                      <img src={photo.thumb} alt={photo.alt} loading="lazy" decoding="async" className="aspect-square w-full object-cover" />
                    </a>
                  </li>
                ))}
              </ul>
            ) : null}
            <Reviewer review={review} />
          </div>
        </>
      ) : null}
    </Modal>
  );
}

function Stars({ rating }: { rating: number }) {
  return (
    <span role="img" aria-label={`${rating}/5`} className="text-15 tracking-stars text-orange">
      {'★'.repeat(rating)}
    </span>
  );
}

function Reviewer({ review }: { review: ReviewView }) {
  const t = useTranslations('reviewForm');
  return (
    <span className="flex items-center gap-3">
      <span aria-hidden className="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-135/srgb from-blue to-orange font-display font-extrabold text-white">
        {initialsOf(review.reviewerName)}
      </span>
      <span className="flex min-w-0 flex-col leading-1.25">
        <span className="flex flex-wrap items-center gap-x-2 text-15 font-semibold">
          {review.reviewerName}
          {review.verified ? (
            <span className="rounded-pill bg-green-tint px-2 py-0.5 text-11 font-semibold text-green-deep" data-testid="review-verified">
              ✓ {t('verified')}
            </span>
          ) : null}
        </span>
        <span className="line-clamp-1 text-13 text-muted">{review.tripLabel}</span>
      </span>
    </span>
  );
}
