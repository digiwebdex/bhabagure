import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** Shapes from api/app/Services/Analytics/AnalyticsReports.php (docs/visitor-analytics.md). */

/** `path` is a website page filter (`page` would clash with a table's page number). */
export type Filters = { from: string; to: string; country?: string; device?: string; source?: string; path?: string }

export type Totals = {
  sessions: number
  /** Distinct daily visitor codes: someone back on three days counts three times. */
  visitors: number
  pageViews: number
  viewsPerSession: number
  engagedSeconds: number
  bounceRate: number | null
  countries: number
  conversions: number
  convertingSessions: number
  conversionRate: number | null
  locatedSessions: number
}

export type Summary = {
  filters: Filters
  totals: Totals
  previous: Totals
  series: { date: string; sessions: number; visitors: number; pageViews: number }[]
  active: number
  activeMinutes: number
  /** Percent of sessions whose country is known; null with no sessions. */
  countryCoverage: number | null
}

export type SessionRow = {
  key: Record<string, string | null>
  label: string
  sessions: number
  visitors: number
  pageViews: number
  convertingSessions: number
  share: number
}

export type EventRow = { key: Record<string, string | null>; label: string; events: number; sessions: number; views: number; bookingsStarted: number; bookings: number; share: number }

export type Breakdown<Row> = { rows: Row[]; total: number; page: number; perPage: number; sessions: number }

export const SESSION_DIMENSIONS = ['country', 'region', 'city', 'landing', 'exit', 'source', 'referrer', 'campaign', 'device', 'browser', 'os', 'language'] as const
export type SessionDimension = (typeof SESSION_DIMENSIONS)[number]
export type EventDimension = 'page' | 'tour' | 'conversion'

export type LiveSession = {
  country: string | null
  countryName: string | null
  region: string | null
  city: string | null
  page: string
  landing: string
  pageViews: number
  startedAt: string
  lastSeenAt: string
  device: string
  source: string
  referrer: string | null
}

export type Live = {
  activeMinutes: number
  sessions: LiveSession[]
  recent: { type: string; page: string; at: string; country: string | null; countryName: string | null; city: string | null; device: string }[]
}

/** `ga4MeasurementId`: Google Analytics 4's web stream id ("G-…"), null when not connected. */
export type AnalyticsSettings = { enabled: boolean; retentionMonths: number; activeMinutes: number; sessionMinutes: number; excludedPaths: string[]; ga4MeasurementId: string | null }

export function query(filters: Filters, extra: Record<string, string | number> = {}): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries({ ...filters, ...extra })) if (value !== undefined && value !== '') params.set(key, String(value))
  return params.toString()
}

export function useSummary(filters: Filters) {
  return useQuery({ queryKey: ['analytics', 'summary', filters], queryFn: ({ signal }) => api.get<Data<Summary>>(`admin/analytics/summary?${query(filters)}`, signal), placeholderData: keepPreviousData })
}

export function useBreakdown<Row = SessionRow>(dimension: SessionDimension | EventDimension, filters: Filters, page = 1, perPage = 25) {
  return useQuery({
    queryKey: ['analytics', 'breakdown', dimension, filters, page, perPage],
    queryFn: ({ signal }) => api.get<Data<Breakdown<Row>>>(`admin/analytics/breakdown/${dimension}?${query(filters, { page, per_page: perPage })}`, signal),
    placeholderData: keepPreviousData,
  })
}

/** Refreshed every 15 seconds while the Live tab is open. */
export function useLive() {
  return useQuery({ queryKey: ['analytics', 'live'], queryFn: ({ signal }) => api.get<Data<Live>>('admin/analytics/live', signal), refetchInterval: 15_000 })
}

export function useAnalyticsSettings(enabled: boolean) {
  return useQuery({ queryKey: ['analytics', 'settings'], queryFn: ({ signal }) => api.get<Data<AnalyticsSettings>>('admin/analytics/settings', signal), enabled })
}

export function useSaveAnalyticsSettings() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (value: AnalyticsSettings) => api.put<Data<AnalyticsSettings>>('admin/analytics/settings', value),
    onSuccess: (response) => {
      client.setQueryData(['analytics', 'settings'], response)
      void client.invalidateQueries({ queryKey: ['analytics'] })
    },
  })
}

/** "BD" → 🇧🇩: two regional-indicator letters; nothing for an unknown country. */
export function flag(code: string | null | undefined): string {
  if (!code || !/^[A-Z]{2}$/.test(code)) return ''
  return String.fromCodePoint(...[...code].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65))
}

/** The Dhaka day `days` before `iso` ('YYYY-MM-DD'). */
export function shiftDay(iso: string, days: number): string {
  const date = new Date(`${iso}T12:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}
