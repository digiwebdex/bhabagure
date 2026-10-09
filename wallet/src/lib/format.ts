import { useMemo } from 'react'

import { formatBdt, formatDate, formatNumber } from '@bhabaghure/format'

import type { AppLocale } from '../i18n'

/** Today in Dhaka as YYYY-MM-DD: the wallet's dates are the proprietor's, whatever the device's clock zone. */
export function todayInDhaka(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date())
}

/** A date (YYYY-MM-DD) as it is, or a timestamp as its day in Dhaka. */
function dhakaDay(iso: string): string {
  return iso.length <= 10 ? iso : new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date(iso))
}

const dhakaTime = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Dhaka', hour: 'numeric', minute: '2-digit' })

/** The shared formatter: English, amounts as "BDT 1,53,000" with Indian grouping (staff software, 2026-09-16). */
export function useFormat() {
  const locale: AppLocale = 'en'

  return useMemo(
    () => ({
      locale,
      bdt: (value: number) => formatBdt(value, locale, { currency: 'code' }),
      number: (value: number) => formatNumber(value, locale),
      date: (iso: string) => formatDate(dhakaDay(iso), locale),
      // When something was recorded or changed (docs/wallet-edits.md): the day and the time, in Dhaka.
      dateTime: (iso: string) => `${formatDate(dhakaDay(iso), locale)}, ${dhakaTime.format(new Date(iso))}`,
      month: () => formatDate(`${todayInDhaka().slice(0, 7)}-01`, locale).replace(/^\S+\s/u, ''),
    }),
    [locale],
  )
}
