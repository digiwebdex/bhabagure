import { useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Card, CardTitle, EmptyState, Loading } from '../../components/ui/layout'
import { downloadDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { flag, query, useBreakdown, type EventDimension, type EventRow, type Filters, type SessionDimension, type SessionRow } from './api'

/** A headline figure and how it compares with the previous period of the same length. */
export function Kpi({ label, value, current, previous, note, testId, lowerIsBetter = false }: { label: string; value: string; current?: number | null; previous?: number | null; note?: string; testId: string; lowerIsBetter?: boolean }) {
  const { t } = useTranslation()
  const { percent } = useFormat()
  const change = current != null && previous != null && previous > 0 ? Math.round(((current - previous) / previous) * 1000) / 10 : null
  const good = change !== null && (lowerIsBetter ? change < 0 : change > 0)
  return (
    <div className="flex flex-col gap-1.5 rounded-16 border border-app-line bg-app-surface px-5 py-4.5" data-testid={testId}>
      <span className="text-13 text-app-muted">{label}</span>
      <span className="font-display text-28 font-extrabold tracking-heading">{value}</span>
      <span className={`text-12 ${change === null ? 'text-app-muted' : good ? 'text-green' : change === 0 ? 'text-app-muted' : 'text-red'}`}>
        {change === null ? (note ?? t('analytics.noComparison')) : t('analytics.vsPrevious', { change: `${change > 0 ? '+' : ''}${percent(change)}` })}
      </span>
    </div>
  )
}

/** Daily visitors and sessions as bars, newest on the right; each bar's figures on hover. */
export function TrendChart({ series }: { series: { date: string; sessions: number; visitors: number; pageViews: number }[] }) {
  const { t } = useTranslation()
  const { number, date } = useFormat()
  const max = Math.max(1, ...series.map((day) => day.sessions))
  const width = 720
  const height = 180
  const slot = width / Math.max(1, series.length)
  const bar = Math.max(2, Math.min(26, slot * 0.7))
  return (
    <figure className="m-0 flex flex-col gap-2">
      <svg viewBox={`0 0 ${width} ${height + 20}`} className="h-auto w-full" role="img" aria-label={t('analytics.trendLabel')} data-testid="analytics-trend">
        {[0.25, 0.5, 0.75, 1].map((line) => (
          <line key={line} x1={0} x2={width} y1={height - line * height} y2={height - line * height} className="stroke-app-line" strokeDasharray="3 4" />
        ))}
        {series.map((day, i) => {
          const sessions = (day.sessions / max) * height
          const visitors = (day.visitors / max) * height
          const x = i * slot + (slot - bar) / 2
          return (
            <g key={day.date}>
              <title>{t('analytics.trendTip', { date: date(day.date), sessions: number(day.sessions), visitors: number(day.visitors), views: number(day.pageViews) })}</title>
              <rect x={x} y={height - sessions} width={bar} height={Math.max(sessions, day.sessions ? 1 : 0)} rx={2} className="fill-blue/25" />
              <rect x={x + bar * 0.2} y={height - visitors} width={bar * 0.6} height={Math.max(visitors, day.visitors ? 1 : 0)} rx={2} className="fill-blue" />
            </g>
          )
        })}
        {series.length > 0 ? (
          <>
            <text x={0} y={height + 16} className="fill-app-muted text-[11px]">{date(series[0].date)}</text>
            <text x={width} y={height + 16} textAnchor="end" className="fill-app-muted text-[11px]">{date(series[series.length - 1].date)}</text>
          </>
        ) : null}
      </svg>
      <figcaption className="flex flex-wrap gap-4 text-12 text-app-muted">
        <span><span className="mr-1.5 inline-block size-2.5 rounded-sm bg-blue" />{t('analytics.visitors')}</span>
        <span><span className="mr-1.5 inline-block size-2.5 rounded-sm bg-blue/25" />{t('analytics.sessions')}</span>
        <span>{t('analytics.peak', { n: number(max) })}</span>
      </figcaption>
    </figure>
  )
}

type Column<Row> = { key: string; header: string; cell: (row: Row) => ReactNode; align?: 'right' }

/**
 * One breakdown: biggest first, a share bar, 25 rows a page, CSV with the same filters. A row can set a filter (a
 * country, a page) with `onPick`.
 */
export function BreakdownCard<Row extends SessionRow | EventRow>({
  title,
  dimension,
  filters,
  columns,
  onPick,
  emptyNote,
  testId,
}: {
  title: string
  dimension: SessionDimension | EventDimension
  filters: Filters
  columns: Column<Row>[]
  onPick?: (row: Row) => void
  emptyNote?: string
  testId: string
}) {
  const { t } = useTranslation()
  const { number, percent } = useFormat()
  // Back to the first page whenever the filters change.
  const filterKey = JSON.stringify(filters)
  const [paging, setPaging] = useState({ filterKey, page: 1 })
  const page = paging.filterKey === filterKey ? paging.page : 1
  const setPage = (next: number) => setPaging({ filterKey, page: next })
  const data = useBreakdown<Row>(dimension, filters, page)

  return (
    <Card padded={false} className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-app-line px-4 py-3">
        <CardTitle title={title} />
        <CsvButton report={dimension} filters={filters} />
      </div>
      {data.isPending ? (
        <Loading />
      ) : data.isError ? (
        <div className="p-4"><ErrorNotice error={data.error} /></div>
      ) : data.data.data.rows.length === 0 ? (
        <EmptyState title={t('analytics.noData')} note={emptyNote} />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-13" data-testid={testId}>
            <thead>
              <tr className="text-left text-12 text-app-muted">
                <th className="px-4 py-2 font-semibold">{t('analytics.columns.name')}</th>
                {columns.map((column) => (
                  <th key={column.key} className={`px-3 py-2 font-semibold whitespace-nowrap ${column.align === 'right' ? 'text-right' : ''}`}>{column.header}</th>
                ))}
                <th className="px-4 py-2 text-right font-semibold">{t('analytics.columns.share')}</th>
              </tr>
            </thead>
            <tbody>
              {data.data.data.rows.map((row) => (
                <tr key={JSON.stringify(row.key)} className="border-t border-app-line">
                  <td className="max-w-80 px-4 py-2">
                    {onPick ? (
                      <button type="button" className="cursor-pointer truncate text-left font-medium text-blue" onClick={() => onPick(row)} title={t('analytics.filterBy', { name: row.label })}>
                        {dimension === 'country' ? `${flag(row.key.country_code)} ` : ''}{row.label}
                      </button>
                    ) : (
                      <span className="block truncate font-medium" title={row.label}>{dimension === 'country' ? `${flag(row.key.country_code)} ` : ''}{row.label}</span>
                    )}
                  </td>
                  {columns.map((column) => (
                    <td key={column.key} className={`px-3 py-2 font-display ${column.align === 'right' ? 'text-right' : ''}`}>{column.cell(row)}</td>
                  ))}
                  <td className="px-4 py-2">
                    <span className="flex items-center justify-end gap-2">
                      <span className="hidden h-1.5 w-20 overflow-hidden rounded-pill bg-app-surface-2 sm:block"><span className="block h-full bg-blue" style={{ width: `${Math.min(100, row.share)}%` }} /></span>
                      <span className="w-12 text-right font-display">{percent(row.share)}</span>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data.data && data.data.data.total > data.data.data.perPage ? (
        <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-2.5 text-13">
          <button type="button" className={buttonClass('outline', 'sm')} disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
          <span className="text-app-muted">{t('common.pageOf', { page: number(page), last: number(Math.ceil(data.data.data.total / data.data.data.perPage)) })}</span>
          <button type="button" className={buttonClass('outline', 'sm')} disabled={page * data.data.data.perPage >= data.data.data.total} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
        </div>
      ) : null}
    </Card>
  )
}

/** The report as CSV with the screen's filters (analytics.export). */
export function CsvButton({ report, filters }: { report: string; filters: Filters }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  if (!can('analytics.export')) return null
  return (
    <button
      type="button"
      className={buttonClass('outline', 'sm')}
      disabled={busy}
      onClick={async () => {
        setBusy(true)
        try {
          await downloadDocument(`admin/analytics/export/${report}?${query(filters)}`, `bhabaghure-analytics-${report}.csv`)
        } catch {
          toast(t('analytics.csvFailed'), 'error')
        }
        setBusy(false)
      }}
    >
      {busy ? t('common.working') : t('analytics.csv')}
    </button>
  )
}

type MapData = { width: number; height: number; source: string; countries: { c: string; n: string; d: string }[]; points: { c: string; n: string; x: number; y: number }[] }

/**
 * Sessions by country on a world map (Natural Earth outlines, public domain; built once by
 * scripts/generate-world-map.cjs, no map service). Darker is more; countries too small for the outlines are dots.
 * A country's figures on hover; clicking filters the whole page by it.
 */
export function WorldMap({ values, onPick }: { values: Record<string, number>; onPick?: (code: string) => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const [map, setMap] = useState<MapData | null>(null)
  const [hover, setHover] = useState<{ code: string; name: string } | null>(null)
  useEffect(() => {
    let live = true
    void import('./world-map.json').then((module) => live && setMap(module.default as MapData))
    return () => {
      live = false
    }
  }, [])
  if (!map) return <Loading />

  const max = Math.max(1, ...Object.values(values))
  // Square-root scale, so one busy country doesn't wash out the rest.
  const shade = (code: string) => {
    const value = values[code] ?? 0
    return value === 0 ? undefined : 0.18 + 0.82 * Math.sqrt(value / max)
  }
  const pick = (code: string) => onPick?.(code)

  return (
    <figure className="m-0 flex flex-col gap-2">
      <svg viewBox={`0 0 ${map.width} ${map.height}`} className="h-auto w-full rounded-12 bg-app-surface-2" role="img" aria-label={t('analytics.mapLabel')} data-testid="analytics-map">
        {map.countries.map((country) => {
          const opacity = shade(country.c)
          return (
            <path
              key={country.c}
              d={country.d}
              data-country={country.c}
              data-sessions={values[country.c] ?? 0}
              className={`stroke-app-surface ${opacity === undefined ? 'fill-app-line' : 'fill-blue'} ${onPick && opacity !== undefined ? 'cursor-pointer' : ''}`}
              fillOpacity={opacity ?? 1}
              strokeWidth={0.5}
              onMouseEnter={() => setHover({ code: country.c, name: country.n })}
              onMouseLeave={() => setHover(null)}
              onClick={() => opacity !== undefined && pick(country.c)}
            >
              <title>{`${country.n}: ${t('analytics.sessionsCount', { count: values[country.c] ?? 0, n: number(values[country.c] ?? 0) })}`}</title>
            </path>
          )
        })}
        {map.points.map((point) => {
          const opacity = shade(point.c)
          return (
            <circle
              key={point.c}
              cx={point.x}
              cy={point.y}
              r={opacity === undefined ? 2.5 : 4.5}
              data-country={point.c}
              className={`stroke-app-surface ${opacity === undefined ? 'fill-app-muted/50' : 'fill-blue'} ${onPick && opacity !== undefined ? 'cursor-pointer' : ''}`}
              fillOpacity={opacity ?? 1}
              strokeWidth={1}
              onMouseEnter={() => setHover({ code: point.c, name: point.n })}
              onMouseLeave={() => setHover(null)}
              onClick={() => opacity !== undefined && pick(point.c)}
            >
              <title>{`${point.n}: ${t('analytics.sessionsCount', { count: values[point.c] ?? 0, n: number(values[point.c] ?? 0) })}`}</title>
            </circle>
          )
        })}
      </svg>
      <figcaption className="flex flex-wrap items-center justify-between gap-2 text-12 text-app-muted">
        <span aria-live="polite">{hover ? `${flag(hover.code)} ${hover.name} · ${t('analytics.sessionsCount', { count: values[hover.code] ?? 0, n: number(values[hover.code] ?? 0) })}` : t('analytics.mapHint')}</span>
        <span className="flex items-center gap-1.5">
          {t('analytics.fewer')}
          <span className="h-2 w-24 rounded-pill bg-gradient-to-r from-blue/20 to-blue" />
          {t('analytics.more')}
        </span>
      </figcaption>
    </figure>
  )
}
