'use client';

import { useEffect, useRef } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { bookingDateFromLink } from '@/lib/tour-booking';
import { useBooking } from '@/state/booking';
import { useTripSearch } from '@/state/trip-search';

/**
 * The brochure's Book links (`?book=1&date=…`, docs/tour-landing-pages.md §7): open the website's own booking form on
 * this tour — on the linked date while it is one of its departures with seats, else the page's next one. Nothing is
 * booked or paid here; the form asks and checks as always. The address then loses the two, so a reload or a shared
 * link shows just the page.
 */
export function BookFromLink({ packageSlug, departures, fallbackDate }: { packageSlug: string; departures: { departsOn: string; seatsLeft: number }[]; fallbackDate: string | null }) {
  const { pricing } = useSiteContent();
  const start = useBooking((state) => state.start);
  const opened = useRef(false);

  useEffect(() => {
    if (opened.current) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('book') !== '1') return;
    opened.current = true;
    const date = bookingDateFromLink(departures, url.searchParams.get('date'), fallbackDate);
    url.searchParams.delete('book');
    url.searchParams.delete('date');
    window.history.replaceState(window.history.state, '', url);
    start({ packageSlug, pax: useTripSearch.getState().pax, date: date ?? undefined, maxPax: pricing.maxTravellers });
  }, [departures, fallbackDate, packageSlug, pricing.maxTravellers, start]);

  return null;
}
