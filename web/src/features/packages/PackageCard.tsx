'use client';

import Image from 'next/image';
import { useTranslations } from 'next-intl';
import type { MouseEvent } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import type { PackageView } from '@/lib/content/views';
import { localizedPath, packagePath } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { useBooking } from '@/state/booking';
import { useSiteUi } from '@/state/site-ui';
import { useTripSearch } from '@/state/trip-search';

import { packageLabels } from './package-labels';

interface PackageCardProps {
  pkg: PackageView;
  /** Per-person price for the search bar's traveller count, from filterPackages(). */
  perPerson: number;
  pax: number;
}

/**
 * The whole card opens the detail modal. The title is a real link to /packages/<slug> stretched
 * over the card, so the card can be opened in a new tab and crawled; the Book button and photo
 * credit sit above that link.
 */
export function PackageCard({ pkg, perPerson, pax }: PackageCardProps) {
  const t = useTranslations('packages');
  const tc = useTranslations('common');
  const f = useFormatters();
  const { pricing } = useSiteContent();
  const openPackage = useSiteUi((state) => state.openPackage);
  const startBooking = useBooking((state) => state.start);
  const labels = packageLabels(pkg, t, f);
  const cover = pkg.images[0];

  const onOpen = (event: MouseEvent<HTMLAnchorElement>) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return; // new tab / window
    event.preventDefault();
    openPackage(pkg.slug, pax);
  };

  const onBook = (event: MouseEvent<HTMLButtonElement>) => {
    event.stopPropagation();
    startBooking({ packageSlug: pkg.slug, pax, date: useTripSearch.getState().date, maxPax: pricing.maxTravellers });
  };

  const caption =
    pax <= 2
      ? pkg.salePrice != null
        ? tc('wasPrice', { amount: f.bdt(pkg.regularPrice) })
        : ' '
      : t('slabCaption', { paxText: f.number(pax) });

  return (
    <article
      data-reveal
      className="group relative flex flex-col overflow-hidden rounded-22 border border-hairline bg-white transition duration-280 ease-lift hover:-translate-y-1.5 hover:border-hairline-hover hover:shadow-card-hover"
    >
      <div className="relative h-card-photo w-full bg-image-placeholder">
        {cover ? (
          <Image src={cover.url} alt={cover.alt} fill sizes="(min-width: 1200px) 380px, (min-width: 640px) 50vw, 100vw" className="object-cover" />
        ) : null}
        <span className="pointer-events-none absolute top-3 left-3 rounded-pill bg-orange-deep px-2.75 py-1.25 font-display text-label text-white uppercase">
          {pkg.destinationLabel}
        </span>
        <span className="pointer-events-none absolute top-3 right-3 rounded-pill bg-white/94 px-2.75 py-1.25 font-display text-12 font-semibold tracking-chip text-ink shadow-hairline">
          {labels.duration}
        </span>
        {cover?.credit ? (
          <a
            href={cover.creditUrl ?? undefined}
            target="_blank"
            rel="noopener noreferrer"
            className="absolute bottom-2.5 left-3 z-10 rounded-pill bg-scrim/62 px-2.25 py-0.75 text-10.5 text-white hover:text-white"
          >
            {cover.credit}
          </a>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col gap-2.75 px-5.5 pt-5.5 pb-6">
        <h3 className="font-display text-19 leading-1.25 font-semibold text-pretty">
          <a
            href={localizedPath(f.locale, packagePath(pkg.slug))}
            onClick={onOpen}
            className="text-ink after:absolute after:inset-0 hover:text-ink focus-visible:outline-none"
          >
            {pkg.title}
          </a>
        </h3>
        <p className="text-14 leading-1.62 text-muted">{pkg.summary}</p>
        <div className="flex flex-wrap gap-2 text-13 text-ink-deep">
          <span className="rounded-8 border border-hairline bg-white px-2.5 py-1 whitespace-nowrap">{labels.air}</span>
          <span className="rounded-8 border border-hairline bg-white px-2.5 py-1 whitespace-nowrap">{labels.groupSize}</span>
        </div>
        <p className="text-13 text-muted">
          {t('departure')}: <strong className="text-ink">{labels.departure}</strong>
        </p>
        <span className="font-display text-12 font-bold tracking-caps text-blue">{t('viewDetails')}</span>

        <div className="mt-auto flex items-end justify-between gap-3 pt-2">
          <div className="flex flex-col">
            <span className="text-12 text-muted">{t('from')}</span>
            <span className="font-display text-price text-orange-deep">{f.bdt(perPerson)}</span>
            <span className="text-12 text-muted">{caption}</span>
          </div>
          <button
            type="button"
            onClick={onBook}
            className="relative z-10 cursor-pointer rounded-pill bg-linear-135/srgb from-blue to-blue-deep px-5 py-2.75 font-sans text-15 font-semibold whitespace-nowrap text-white shadow-knob-soft transition hover:from-orange-bright hover:to-orange-deep hover:shadow-cta-hover"
          >
            {t('book')}
          </button>
        </div>
      </div>
    </article>
  );
}
