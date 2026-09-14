'use client';

import { useMemo } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { filterPackages } from '@/lib/filter-packages';
import { useTripSearch } from '@/state/trip-search';

/** The search bar's counter and the package grid both call this — one filter, one result. */
export function usePackageResults() {
  const { packages, pricing } = useSiteContent();
  const destination = useTripSearch((state) => state.destination);
  const budget = useTripSearch((state) => state.budget);
  const pax = useTripSearch((state) => state.pax);

  return useMemo(
    () => ({ pax, ...filterPackages(packages, { destination, budget, pax }, pricing.slabs) }),
    [packages, pricing.slabs, destination, budget, pax],
  );
}
