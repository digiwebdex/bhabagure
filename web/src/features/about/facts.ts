import type { getTranslations } from 'next-intl/server';

import type { SiteViews } from '@/lib/content/views';
import type { formattersFor } from '@/lib/formatters';

/** The four About fact cards — packages, destinations, licence, opening hours — shared by About and the team page. */
export function aboutFacts(
  t: Awaited<ReturnType<typeof getTranslations>>,
  f: ReturnType<typeof formattersFor>,
  stats: SiteViews['stats'],
  settings: SiteViews['settings'],
): { value: string; label: string }[] {
  return [
    { value: f.number(stats.packages), label: t('about.facts.packages') },
    { value: f.number(stats.destinations), label: t('about.facts.destinations') },
    { value: f.digits(settings.civilAviationNo), label: t('about.facts.licence') },
    { value: `${f.number(settings.hours.opens)}–${f.number(settings.hours.closes - 12)}`, label: t('about.facts.hours') },
  ];
}
