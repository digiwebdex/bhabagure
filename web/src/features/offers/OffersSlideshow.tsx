'use client';

import Image from 'next/image';
import { useTranslations } from 'next-intl';
import { type ReactNode } from 'react';

import { SlideArrow, useSlideshow } from '@/components/ui/Slideshow';
import { Link } from '@/i18n/navigation';
import type { SiteViews } from '@/lib/content/views';
import { useFormatters } from '@/lib/use-formatters';

const EVERY_MS = 6000;

/**
 * The offer banners under the hero video (docs/offer-banners.md): one wide picture at a time, changing by itself every
 * six seconds. It waits while someone is pointing at it, using its buttons or reading another tab, and never moves on
 * its own for a visitor who asks for reduced motion (useSlideshow). Swipe, the arrows and the dots all work; each banner
 * can lead to a package, an offer page or nowhere. Renders nothing until Admin → Offer banners has a published one.
 */
export function OffersSlideshow({ offers }: { offers: SiteViews['offers'] }) {
  const t = useTranslations('offers');
  // Numbers go into the labels formatted, so the Bangla site reads them in Bangla digits.
  const f = useFormatters();
  const { track, current, show, step, holdProps } = useSlideshow(offers.length, EVERY_MS);

  if (offers.length === 0) return null;

  return (
    <section id="offers" aria-roledescription="carousel" aria-label={t('label')} className="mx-auto w-full max-w-site px-section-x pt-6" {...holdProps}>
      <div className="relative">
        <ul ref={track} className="scrollbar-none m-0 flex snap-x snap-mandatory list-none gap-0 overflow-x-auto p-0" data-testid="offer-banners">
          {offers.map((offer, index) => (
            <li key={offer.image.url} className="w-full shrink-0 snap-center" aria-roledescription="slide" aria-label={t('slide', { n: f.number(index + 1), total: f.number(offers.length) })}>
              <Banner offer={offer} priority={index === 0} />
            </li>
          ))}
        </ul>

        {offers.length > 1 ? (
          <>
            <SlideArrow side="left" label={t('previous')} onClick={() => step(-1)} />
            <SlideArrow side="right" label={t('next')} onClick={() => step(1)} />
          </>
        ) : null}
      </div>

      {offers.length > 1 ? (
        <div className="mt-3 flex justify-center gap-2">
          {offers.map((offer, index) => (
            <button
              key={offer.image.url}
              type="button"
              aria-label={t('goTo', { n: f.number(index + 1) })}
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
