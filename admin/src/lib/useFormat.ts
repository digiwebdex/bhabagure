import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { formatBdt, formatBdtCompact, formatDate, formatDateRange, formatNumber, formatPercent, formatRelativeAge, formatWeekdayDate, localizeDigits, type NumberFormatOptions } from '@bhabaghure/format'

import type { AppLocale } from '../i18n'

// The business runs on Dhaka time whatever the staff member's device says.
const DHAKA = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

function dhaka(iso: string): { date: string; time: string } {
  const parts = Object.fromEntries(DHAKA.formatToParts(new Date(iso)).map((part) => [part.type, part.value]))
  return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` }
}

/** Today's calendar date in Dhaka (YYYY-MM-DD) — not the device's or UTC's, which differ between midnight and 06:00. */
export function todayInDhaka(): string {
  return dhaka(new Date().toISOString()).date
}

/** Binds the shared formatter to the active language. The only way the admin renders numbers. */
export function useFormat() {
  const { i18n } = useTranslation()
  const locale: AppLocale = i18n.resolvedLanguage === 'en' ? 'en' : 'bn'

  return useMemo(
    () => ({
      locale,
      // Decimals only: "BDT" is for SMS text, which the API writes; every screen shows "৳".
      bdt: (value: number | string, options?: NumberFormatOptions) => formatBdt(value, locale, { decimals: options?.decimals }),
      /** Summary figures: '৳ 14.2L' / '৳ ১৪.২ লাখ', '৳ 2.4Cr' / '৳ ২.৪ কোটি'; below one lakh the full amount. */
      bdtCompact: (value: number | string) => formatBdtCompact(value, locale),
      number: (value: number | string, options?: NumberFormatOptions) => formatNumber(value, locale, options),
      percent: (value: number) => formatPercent(value, locale),
      /** 'YYYY-MM-DD' (or an ISO timestamp, taken on its Dhaka date) → '24 Sep 2026' / '২৪ সেপ্টেম্বর ২০২৬'. */
      date: (iso: string) => formatDate(iso.length > 10 ? dhaka(iso).date : iso, locale),
      /** An ISO timestamp in Dhaka time → '24 Oct 2026, 10:00' / '২৪ অক্টোবর ২০২৬, ১০:০০'. */
      dateTime: (iso: string) => {
        const { date, time } = dhaka(iso)
        return `${formatDate(date, locale)}, ${localizeDigits(time, locale)}`
      },
      /** Identifiers (phone, codes): localized digits, never grouped. */
      digits: (text: string) => localizeDigits(text, locale),
      /** How long something has waited, from whole minutes: '26 h' / '২৬ ঘণ্টা'. */
      relativeAge: (minutes: number) => formatRelativeAge(minutes, locale),
      /** 'Tuesday, 22 September 2026' — for 'YYYY-MM-DD', or today's Dhaka date when omitted. */
      weekdayDate: (iso?: string) => formatWeekdayDate(iso ?? todayInDhaka(), locale),
      dateRange: (start: string, end: string) => formatDateRange(start, end, locale),
    }),
    [locale],
  )
}
