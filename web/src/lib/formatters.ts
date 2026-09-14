import { formatBdt, formatBdtCompact, formatDate, formatNumber, formatPercent, localizeDigits } from '@bhabaghure/format';

import type { AppLocale } from '@/i18n/routing';

/** The shared formatter bound to one language. Usable from server and client components. */
export function formattersFor(locale: AppLocale) {
  return {
    locale,
    bdt: (value: number | string) => formatBdt(value, locale),
    /** Summary figures: '৳ 14.2L' / '৳ ১৪.২ লাখ'; below one lakh the full amount. */
    bdtCompact: (value: number | string) => formatBdtCompact(value, locale),
    number: (value: number | string) => formatNumber(value, locale),
    percent: (value: number) => formatPercent(value, locale),
    date: (isoDate: string) => formatDate(isoDate, locale),
    /** Identifiers shown with localized digits but no grouping: phone, licence number. */
    digits: (value: string) => localizeDigits(value, locale),
  };
}

export type Formatters = ReturnType<typeof formattersFor>;
