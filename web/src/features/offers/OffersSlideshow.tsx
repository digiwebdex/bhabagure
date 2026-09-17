'use client';

import Image from 'next/image';
import { useTranslations } from 'next-intl';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

import { Link } from '@/i18n/navigation';
import type { SiteViews } from '@/lib/content/views';

const EVERY_MS = 6000;

/**
 * The offer banners under the hero video (docs/offer-banners.md): one wide picture at a time, changing by itself every
 * six seconds. It waits while someone is pointing at it, using its buttons or reading another tab, and never moves on
 * its own for a visitor who asks for reduced motion. Swipe, the arrows and the dots all work; each banner can lead to a
 * package, an offer page or nowhere. Renders nothing until Admin → Offer banners has a published one.
 */
export function OffersSlideshow({ offers }: { offers: SiteViews['offers'] }) {
  const t = useTranslations('offers');
  const track = useRef<HTMLUListElement>(null);
  const [current, setCurrent] = useState(0);
  const [held, setHeld] = useState(false);

  const show = useCallback((index: number) => {
    const list = track.current;
    if (!list) return;
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    list.scrollTo({ left: index * list.clientWidth, behavior: still ? 'auto' : 'smooth' });
  }, []);

  // The scroll position is the truth: a swipe, a click on a dot and the timer all end up here.
  useEffect(() => {
    const list = track.current;
    if (!list) return;
    const onScroll = () => setCurrent(Math.round(list.scrollLeft / Math.max(list.clientWidth, 1)));
    list.addEventListener('scroll', onScroll, { passive: true });
    return () => list.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    if (offers.length < 2 || held) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return; // nobody is looking
      const list = track.current;
      if (!list) return;
      const at = Math.round(list.scrollLeft / Math.max(list.clientWidth, 1));
      show((at + 1) % offers.length);
    }, EVERY_MS);
    return () => window.clearInterval(timer);
  }, [offers.length, held, show]);

  if (offers.length === 0) return null;

  return (
    <section
      id="offers"
      aria-roledescription="carousel"
      aria-label={t('label')}
      className="mx-auto w-full max-w-site px-section-x pt-6"
      onMouseEnter={() => setHeld(true)}
      onMouseLeave={() => setHeld(false)}
      onFocusCapture={() => setHeld(true)}
      onBlurCapture={() => setHeld(false)}
    >
      <div className="relative">
        <ul ref={track} className="scrollbar-none m-0 flex snap-x snap-mandatory list-none gap-0 overflow-x-auto p-0" data-testid="offer-banners">
          {offers.map((offer, index) => (
            <li key={offer.image.url} className="w-full shrink-0 snap-center" aria-roledescription="slide" aria-label={t('slide', { n: index + 1, total: offers.length })}>
              <Banner offer={offer} priority={index === 0} />
            </li>
          ))}
        </ul>

        {offers.length > 1 ? (
          <>
            <Arrow side="left" label={t('previous')} onClick={() => show((current - 1 + offers.length) % offers.length)} />
            <Arrow side="right" label={t('next')} onClick={() => show((current + 1) % offers.length)} />
          </>
        ) : null}
      </div>

      {offers.length > 1 ? (
        <div className="mt-3 flex justify-center gap-2">
          {offers.map((offer, index) => (
            <button
              key={offer.image.url}
              type="button"
              aria-label={t('goTo', { n: index + 1 })}
              aria-current={index === current}
              onClick={() => show(index)}
              className={`h-2 cursor-pointer rounded-pill border-0 transition-all duration-300 ${index === current ? 'w-6 bg-orange-deep' : 'w-2 bg-hairline-hover hover:bg-muted-label'}`}
            />
          ))}
        </div>
      ) : null}
    </section>
  );
}

function Banner({ offer, priority }: { offer: SiteViews['offers'][number]; priority: boolean }) {
  const picture = (
    <Image
      src={offer.image.url}
      alt={offer.image.alt || offer.title}
      width={1600}
      height={533}
      priority={priority}
      sizes="(min-width: 1200px) 1140px, 100vw"
      className="aspect-3/1 w-full object-cover transition-transform duration-500 ease-lift group-hover:scale-102"
    />
  );
  const frame = (children: ReactNode) => <div className="group block overflow-hidden rounded-fluid bg-image-placeholder shadow-card-soft">{children}</div>;

  if (!offer.linkUrl) return frame(picture);
  // A page of this site keeps the visitor's language; anywhere else opens in its own tab.
  return offer.linkUrl.startsWith('/')
    ? frame(
        <Link href={offer.linkUrl} className="block">
          {picture}
        </Link>,
      )
    : frame(
        <a href={offer.linkUrl} target="_blank" rel="noopener noreferrer" className="block">
          {picture}
        </a>,
      );
}

function Arrow({ side, label, onClick }: { side: 'left' | 'right'; label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      aria-label={label}
      onClick={onClick}
      className={`absolute top-1/2 hidden size-10 -translate-y-1/2 cursor-pointer items-center justify-center rounded-pill border border-hairline bg-white/90 text-ink shadow-raised backdrop-blur-sm transition hover:bg-white sm:flex ${side === 'left' ? 'left-3' : 'right-3'}`}
    >
      <svg viewBox="0 0 24 24" aria-hidden="true" className="size-5 fill-none stroke-current stroke-2">
        <path d={side === 'left' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7'} strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </button>
  );
}
