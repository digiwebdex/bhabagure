import { formatAudience, formatBdt, formatBdtCompact, formatDate, formatDateRange, formatNumber, formatPercent, localizeDigits } from '@bhabaghure/format';

import type { AppLocale } from '@/i18n/routing';

/** The shared formatter bound to one language. Usable from server and client components. */
export function formattersFor(locale: AppLocale) {
  return {
    locale,
    bdt: (value: number | string) => formatBdt(value, locale),
    /** Summary figures: '৳ 14.2L' / '৳ ১৪.২ লাখ'; below one lakh the full amount. */
    bdtCompact: (value: number | string) => formatBdtCompact(value, locale),
    number: (value: number | string) => formatNumber(value, locale),
    /** Followers and subscribers: '1.1M' / '১১ লাখ', rounded down. */
    audience: (value: number | string) => formatAudience(value, locale),
    percent: (value: number) => formatPercent(value, locale),
    date: (isoDate: string) => formatDate(isoDate, locale),
    /** A trip's dates: '20–26 October 2026' / '২০–২৬ অক্টোবর ২০২৬'; without an end, the start date alone. */
    dateRange: (startIso: string, endIso: string | null) => (endIso ? formatDateRange(startIso, endIso, locale) : formatDate(startIso, locale)),
    /** "2026-09" → 'September 2026' / 'সেপ্টেম্বর ২০২৬' (the admin's month, too). */
    month: (yearMonth: string) => formatDate(`${yearMonth}-01`, locale).replace(/^\S+\s/u, ''),
    /** Identifiers shown with localized digits but no grouping: phone, licence number. */
    digits: (value: string) => localizeDigits(value, locale),
  };
}

export type Formatters = ReturnType<typeof formattersFor>;
