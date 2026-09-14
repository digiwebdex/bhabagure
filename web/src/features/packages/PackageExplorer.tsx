'use client';

import { useTranslations } from 'next-intl';
import type { ReactNode } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { usePackageResults } from '@/features/search/usePackageResults';
import { useTripSearch } from '@/state/trip-search';

import { PackageCard } from './PackageCard';

/** Destination chips, the card grid and the empty state. Chips and the search select share one filter. */
export function PackageExplorer({ heading }: { heading: ReactNode }) {
  const t = useTranslations('packages');
  const { destinations } = useSiteContent();
  const destination = useTripSearch((state) => state.destination);
  const setDestination = useTripSearch((state) => state.setDestination);
  const reset = useTripSearch((state) => state.reset);
  const { cards, pax } = usePackageResults();

  const chip = (value: string, label: string) => {
    const active = destination === value;
    return (
      <button
        key={value}
        type="button"
        aria-pressed={active}
        onClick={() => setDestination(value)}
        className={`cursor-pointer rounded-pill border-chip px-4.5 py-2.25 font-display text-14 font-semibold whitespace-nowrap transition-colors duration-150 ${
          active ? 'border-blue bg-blue text-white' : 'border-input bg-white text-ink-deep hover:border-orange hover:text-orange'
        }`}
      >
        {label}
      </button>
    );
  };

  return (
    <>
      <div className="flex flex-col gap-5.5">
        {heading}
        <div className="flex flex-wrap gap-2">
          {chip('any', t('all'))}
          {destinations.map((d) => chip(d.slug, d.label))}
        </div>
      </div>

      {cards.length === 0 ? (
        <div className="flex flex-col items-center gap-3 rounded-20 border border-dashed border-input bg-white p-fluid-28-44 text-center">
          <span className="font-display text-12 font-extrabold tracking-label text-orange-deep uppercase">{t('empty.kicker')}</span>
          <strong className="max-w-34ch text-fluid-19-24 font-bold tracking-heading text-pretty">{t('empty.title')}</strong>
          <span className="max-w-48ch text-15 leading-1.6 text-muted text-pretty">{t('empty.note')}</span>
          <div className="mt-1 flex flex-wrap justify-center gap-2.5">
            <button type="button" onClick={reset} className={buttonClass('outlineInk', 'md')}>
              {t('empty.reset')}
            </button>
            <a href="#contact" className={buttonClass('cta', 'md', 'px-6')}>
              {t('empty.cta')}
            </a>
          </div>
        </div>
      ) : (
        <div className="grid-auto-fill-280 grid gap-5">
          {cards.map(({ pkg, perPerson }) => (
            <PackageCard key={pkg.slug} pkg={pkg} perPerson={perPerson} pax={pax} />
          ))}
        </div>
      )}
    </>
  );
}
