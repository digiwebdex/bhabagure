import { useMemo } from 'react'

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

/**
 * The shared formatter for the staff panel, which is English only (2026-09-16). Amounts carry the code, "BDT 1,53,000":
 * the taka sign stays on what customers read — the website, the portal and printed invoices.
 */
export function useFormat() {
  const locale: AppLocale = 'en'

  return useMemo(
    () => ({
      locale,
      bdt: (value: number | string, options?: NumberFormatOptions) => formatBdt(value, locale, { decimals: options?.decimals, currency: 'code' }),
      /** Summary figures: 'BDT 14.2L', 'BDT 2.4Cr'; below one lakh the full amount. */
      bdtCompact: (value: number | string) => formatBdtCompact(value, locale, { currency: 'code' }),
      number: (value: number | string, options?: NumberFormatOptions) => formatNumber(value, locale, options),
      percent: (value: number) => formatPercent(value, locale),
      /** 'YYYY-MM-DD' (or an ISO timestamp, taken on its Dhaka date) → '24 Sep 2026'. */
      date: (iso: string) => formatDate(iso.length > 10 ? dhaka(iso).date : iso, locale),
      /** 'YYYY-MM' → 'September 2026' (the date's day dropped). */
      month: (yearMonth: string) => formatDate(`${yearMonth}-01`, locale).replace(/^\S+\s/u, ''),
      /** An ISO timestamp in Dhaka time → '24 Oct 2026, 10:00'. */
      dateTime: (iso: string) => {
        const { date, time } = dhaka(iso)
        return `${formatDate(date, locale)}, ${localizeDigits(time, locale)}`
      },
      /** Identifiers (phone, codes): never grouped. */
      digits: (text: string) => localizeDigits(text, locale),
      /** How long something has waited, from whole minutes: '26 h'. */
      relativeAge: (minutes: number) => formatRelativeAge(minutes, locale),
      /** 'Tuesday, 22 September 2026' — for 'YYYY-MM-DD', or today's Dhaka date when omitted. */
      weekdayDate: (iso?: string) => formatWeekdayDate(iso ?? todayInDhaka(), locale),
      dateRange: (start: string, end: string) => formatDateRange(start, end, locale),
    }),
    [locale],
  )
}
