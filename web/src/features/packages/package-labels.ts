import type { PackageView } from '@/lib/content/views';
import { pickedDeparture } from '@/lib/departures';
import type { Formatters } from '@/lib/formatters';

type Translate = (key: string, values?: Record<string, string | number>) => string;

type LabelSource = Pick<PackageView, 'code' | 'durationDays' | 'durationNights' | 'includesAirfare' | 'minPax' | 'departureMode'> &
  Partial<Pick<PackageView, 'groupTour' | 'departures'>>;

/**
 * Card, modal and package page describe a package the same way. `t` is the "packages" message
 * namespace; every number goes through the shared formatter. A package is a fixed-departure group tour or a
 * customized trip (docs/fixed-departure-group-tours.md). `picked`: the departure chosen on the package page.
 */
export function packageLabels(pkg: LabelSource, t: Translate, f: Formatters, picked: string | null = null) {
  const duration =
    pkg.durationNights != null
      ? t('durationNights', { nightsText: f.number(pkg.durationNights), daysText: f.number(pkg.durationDays) })
      : t('durationDays', { days: pkg.durationDays, daysText: f.number(pkg.durationDays) });

  const air = pkg.includesAirfare === true ? t('airIncluded') : pkg.includesAirfare === false ? t('landOnly') : t('airAsk');

  const groupSize = pkg.groupTour
    ? t('groupTourFixed')
    : pkg.minPax != null
      ? `${t('customizedTrip')} · ${t('minPax', { countText: f.number(pkg.minPax) })}`
      : t('customizedTrip');

  // A group tour names the departure it is shown on — the featured one while it has seats, else the next with seats
  // (docs/departure-prices.md), or the one picked on its page — by the trip's dates when the return is known; a
  // customized trip how its dates work.
  const next = pkg.groupTour && pkg.departures ? pickedDeparture(pkg.departures, picked) : undefined;
  const mode = pkg.groupTour
    ? next
      ? next.returnsOn
        ? f.dateRange(next.departsOn, next.returnsOn)
        : t('departsOn', { date: f.date(next.departsOn) })
      : t('datesSoon')
    : pkg.departureMode === 'regular'
      ? t('departureRegular')
      : pkg.departureMode === 'on_request'
        ? t('departureOnRequest')
        : t('departureAnyDate');

  return { duration, air, groupSize, departure: `${pkg.code} · ${mode}` };
}
