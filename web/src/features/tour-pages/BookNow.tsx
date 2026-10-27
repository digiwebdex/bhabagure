'use client';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass, type ButtonSize, type ButtonVariant } from '@/components/ui/button';
import { useBooking } from '@/state/booking';
import { useTripSearch } from '@/state/trip-search';

/**
 * Book Now on a landing page (docs/tour-landing-pages.md): the website's own booking form, on this tour and the date the
 * button is for — no booking of its own, and the price is the form's.
 */
export function BookNow({
  packageSlug,
  departsOn,
  label,
  variant = 'cta',
  size = 'lg',
  className = '',
}: {
  packageSlug: string;
  departsOn: string | null;
  label: string;
  variant?: ButtonVariant;
  size?: ButtonSize;
  className?: string;
}) {
  const { pricing } = useSiteContent();
  const start = useBooking((state) => state.start);
  return (
    <button
      type="button"
      data-testid="tour-book-now"
      onClick={() => start({ packageSlug, pax: useTripSearch.getState().pax, date: departsOn ?? undefined, maxPax: pricing.maxTravellers })}
      className={buttonClass(variant, size, `shadow-cta ${className}`)}
    >
      {label}
    </button>
  );
}
