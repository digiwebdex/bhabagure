import type { PackageView } from '@/lib/content/views';
import type { Formatters } from '@/lib/formatters';

type Translate = (key: string, values?: Record<string, string | number>) => string;

type LabelSource = Pick<PackageView, 'code' | 'durationDays' | 'durationNights' | 'includesAirfare' | 'groupMode' | 'minPax' | 'departureMode'>;

/**
 * Card, modal and package page describe a package the same way. `t` is the "packages" message
 * namespace; every number goes through the shared formatter.
 */
export function packageLabels(pkg: LabelSource, t: Translate, f: Formatters) {
  const duration =
    pkg.durationNights != null
      ? t('durationNights', { nightsText: f.number(pkg.durationNights), daysText: f.number(pkg.durationDays) })
      : t('durationDays', { days: pkg.durationDays, daysText: f.number(pkg.durationDays) });

  const air = pkg.includesAirfare === true ? t('airIncluded') : pkg.includesAirfare === false ? t('landOnly') : t('airAsk');

  const groupSize =
    pkg.groupMode === 'group'
      ? t('groupTour')
      : pkg.minPax != null
        ? t('minPax', { countText: f.number(pkg.minPax) })
        : t('anySize');

  const mode =
    pkg.departureMode === 'regular' ? t('departureRegular') : pkg.departureMode === 'on_request' ? t('departureOnRequest') : t('departureAnyDate');

  return { duration, air, groupSize, departure: `${pkg.code} · ${mode}` };
}
