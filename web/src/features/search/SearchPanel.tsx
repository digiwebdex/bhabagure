'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';

import { AirQuoteForm } from './AirQuoteForm';
import { HotelQuoteForm } from './HotelQuoteForm';
import { TripSearchForm } from './TripSearchForm';
import { VisaFinder } from './VisaFinder';

type Tab = 'trip' | 'air' | 'hotel' | 'visa';

/** Sits directly under the hero: package search, the air-ticket and hotel quotation requests, and the visas we process (once the CMS has one). */
export function SearchPanel() {
  const t = useTranslations('search');
  const [tab, setTab] = useState<Tab>('trip');
  const { visaCountries } = useSiteContent();

  const tabButton = (value: Tab, label: string) => {
    const active = tab === value;
    return (
      <button
        type="button"
        role="tab"
        aria-selected={active}
        aria-controls={`search-panel-${value}`}
        id={`search-tab-${value}`}
        onClick={() => setTab(value)}
        className={`cursor-pointer rounded-pill border-chip px-4.5 py-2.25 text-14 font-semibold whitespace-nowrap ${
          active ? 'border-blue bg-blue text-white' : 'border-input bg-transparent text-muted hover:border-blue'
        }`}
      >
        {label}
      </button>
    );
  };

  return (
    <section id="search" className="border-b border-hairline-soft bg-white">
      <div className="mx-auto flex max-w-site flex-col gap-4.5 px-section-x py-fluid-26-38">
        <div role="tablist" className="flex flex-wrap gap-1.75">
          {tabButton('trip', t('tabTrip'))}
          {tabButton('air', t('tabAir'))}
          {tabButton('hotel', t('tabHotel'))}
          {visaCountries.length > 0 ? tabButton('visa', t('tabVisa')) : null}
        </div>
        <div role="tabpanel" id={`search-panel-${tab}`} aria-labelledby={`search-tab-${tab}`}>
          {tab === 'trip' ? <TripSearchForm /> : tab === 'air' ? <AirQuoteForm /> : tab === 'hotel' ? <HotelQuoteForm /> : <VisaFinder />}
        </div>
      </div>
    </section>
  );
}
