'use client';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { useBooking } from '@/state/booking';
import { useTripSearch } from '@/state/trip-search';

export function BookSeatButton({ packageSlug, departsOn, label }: { packageSlug: string; departsOn: string | null; label: string }) {
  const { pricing } = useSiteContent();
  const start = useBooking((state) => state.start);
  return (
    <button
      type="button"
      onClick={() => {
        const search = useTripSearch.getState();
        start({ packageSlug, pax: search.pax, date: departsOn ?? search.date, maxPax: pricing.maxTravellers });
      }}
      className={buttonClass('cta', 'none', 'p-2.75 text-14 hover:-translate-y-px')}
    >
      {label}
    </button>
  );
}
