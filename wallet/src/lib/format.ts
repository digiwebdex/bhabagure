import { useMemo } from 'react'

import { formatBdt, formatDate, formatNumber } from '@bhabaghure/format'

import type { AppLocale } from '../i18n'

/** Today in Dhaka as YYYY-MM-DD: the wallet's dates are the proprietor's, whatever the device's clock zone. */
export function todayInDhaka(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date())
}

/** The shared formatter: English, amounts as "BDT 1,53,000" with Indian grouping (staff software, 2026-09-16). */
export function useFormat() {
  const locale: AppLocale = 'en'

  return useMemo(
    () => ({
      locale,
      bdt: (value: number) => formatBdt(value, locale, { currency: 'code' }),
      number: (value: number) => formatNumber(value, locale),
      date: (iso: string) => formatDate(iso.slice(0, 10), locale),
      month: () => formatDate(`${todayInDhaka().slice(0, 7)}-01`, locale).replace(/^\S+\s/u, ''),
    }),
    [locale],
  )
}
