import { todayInDhaka } from '../../lib/useFormat'

/**
 * The periods the transaction list is read over, as the client's books offer them. Every date is worked out in Dhaka
 * time, because that is the day the money moved — not the day the browser happens to be in.
 */
export const PERIODS = ['today', 'yesterday', 'this_week', 'this_month', 'last_month', 'this_year', 'last_12_months', 'last_24_months', 'custom', 'all'] as const

export type Period = (typeof PERIODS)[number]

const shift = (iso: string, days: number): string => {
  const date = new Date(`${iso}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

const months = (iso: string, back: number): string => {
  const date = new Date(`${iso}T00:00:00Z`)
  date.setUTCMonth(date.getUTCMonth() - back)
  return date.toISOString().slice(0, 10)
}

/** @returns the from and to dates a period covers, or empty strings for "all" and "custom" (which the user fills in) */
export function periodRange(period: Period): { from: string; to: string } {
  const today = todayInDhaka()

  switch (period) {
    case 'today':
      return { from: today, to: today }
    case 'yesterday':
      return { from: shift(today, -1), to: shift(today, -1) }
    // The week the books keep: Saturday to Friday, as the working week runs in Bangladesh.
    case 'this_week': {
      const weekday = new Date(`${today}T00:00:00Z`).getUTCDay()
      return { from: shift(today, -((weekday + 1) % 7)), to: today }
    }
    case 'this_month':
      return { from: `${today.slice(0, 7)}-01`, to: today }
    case 'last_month': {
      const first = months(`${today.slice(0, 7)}-01`, 1)
      return { from: first, to: shift(`${today.slice(0, 7)}-01`, -1) }
    }
    case 'this_year':
      return { from: `${today.slice(0, 4)}-01-01`, to: today }
    case 'last_12_months':
      return { from: months(today, 12), to: today }
    case 'last_24_months':
      return { from: months(today, 24), to: today }
    default:
      return { from: '', to: '' }
  }
}
