import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { formatBdt, formatDate, formatNumber } from '@bhabaghure/format'

import type { AppLocale } from '../i18n'

/** Today in Dhaka as YYYY-MM-DD: the wallet's dates are the proprietor's, whatever the device's clock zone. */
export function todayInDhaka(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date())
}

/** The shared formatter bound to the language: ৳ with Indian grouping, Bangla digits in Bangla. */
export function useFormat() {
  const { i18n } = useTranslation()
  const locale: AppLocale = i18n.resolvedLanguage === 'en' ? 'en' : 'bn'

  return useMemo(
    () => ({
      locale,
      bdt: (value: number) => formatBdt(value, locale),
      number: (value: number) => formatNumber(value, locale),
      date: (iso: string) => formatDate(iso.slice(0, 10), locale),
      month: () => formatDate(`${todayInDhaka().slice(0, 7)}-01`, locale).replace(/^\S+\s/u, ''),
    }),
    [locale],
  )
}
