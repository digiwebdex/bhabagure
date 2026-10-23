import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, Switch, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { flag, shiftDay, useAnalyticsSettings, useBreakdown, useLive, useSaveAnalyticsSettings, useSummary, type AnalyticsSettings, type EventRow, type Filters, type SessionRow } from './api'
import { BreakdownCard, CsvButton, Kpi, TrendChart, WorldMap } from './parts'

const TABS = ['overview', 'locations', 'live', 'pages', 'sources', 'devices', 'conversions', 'settings'] as const
type Tab = (typeof TABS)[number]
const PERIODS = ['today', 'yesterday', '7', '30', '90', 'custom'] as const
type Period = (typeof PERIODS)[number]

/**
 * Website → Visitor analytics (docs/visitor-analytics.md): the public website's visits, without cookies or IP
 * addresses. The period and filters live in the address, so a view can be shared or reloaded.
 */
export function AnalyticsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const today = todayInDhaka()
  const tab: Tab = TABS.includes(params.get('tab') as Tab) && (params.get('tab') !== 'settings' || can('analytics.manage')) ? (params.get('tab') as Tab) : 'overview'
  const period: Period = PERIODS.includes(params.get('period') as Period) ? (params.get('period') as Period) : '30'
  const range = (): { from: string; to: string } => {
    switch (period) {
      case 'today':
        return { from: today, to: today }
      case 'yesterday':
        return { from: shiftDay(today, -1), to: shiftDay(today, -1) }
      case 'custom':
        return { from: params.get('from') ?? shiftDay(today, -29), to: params.get('to') ?? today }
      default:
        return { from: shiftDay(today, -(Number(period) - 1)), to: today }
    }
  }
  const filters: Filters = {
    ...range(),
    country: params.get('country') ?? undefined,
    device: params.get('device') ?? undefined,
    source: params.get('source') ?? undefined,
    path: params.get('path') ?? undefined,
  }
  const set = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === '') next.delete(key)
      else next.set(key, value)
    }
    setParams(next, { replace: true })
  }
  const setFilter = (key: 'country' | 'device' | 'source' | 'path', value: string | null) => set({ [key]: value })

  return (
    <>
      <PageHeader
        title={t('analytics.title')}
        subtitle={t('analytics.subtitle')}
        actions={
          <button type="button" className={buttonClass('outline')} onClick={() => window.print()}>
            {t('analytics.print')}
          </button>
        }
      />
      <div className="flex flex-col gap-3 print:hidden">
        <Chips label={t('analytics.tab')} value={tab} onChange={(value) => set({ tab: value === 'overview' ? null : value })} options={TABS.filter((value) => value !== 'settings' || can('analytics.manage')).map((value) => ({ value, label: t(`analytics.tabs.${value}`) }))} />
        {tab !== 'live' && tab !== 'settings' ? (
          <div className="flex flex-wrap items-end gap-3">
            <Chips label={t('analytics.period')} value={period} onChange={(value) => set({ period: value === '30' ? null : value, ...(value !== 'custom' ? { from: null, to: null } : {}) })} options={PERIODS.map((value) => ({ value, label: t(`analytics.periods.${value}`) }))} />
            {period === 'custom' ? (
              <>
                <TextInput label={t('analytics.from')} type="date" value={filters.from} max={filters.to} onChange={(from) => set({ from })} />
                <TextInput label={t('analytics.to')} type="date" value={filters.to} min={filters.from} max={today} onChange={(to) => set({ to })} />
              </>
            ) : null}
          </div>
        ) : null}
        <ActiveFilters filters={filters} onClear={setFilter} />
      </div>

      {tab === 'overview' ? <Overview filters={filters} /> : null}
      {tab === 'locations' ? <Locations filters={filters} onCountry={(code) => setFilter('country', code)} /> : null}
      {tab === 'live' ? <LiveTab /> : null}
      {tab === 'pages' ? <Pages filters={filters} onPage={(page) => setFilter('path', page)} /> : null}
      {tab === 'sources' ? <Sources filters={filters} onSource={(source) => setFilter('source', source)} /> : null}
      {tab === 'devices' ? <Devices filters={filters} onDevice={(device) => setFilter('device', device)} /> : null}
      {tab === 'conversions' ? <Conversions filters={filters} /> : null}
      {tab === 'settings' ? <SettingsTab /> : null}
      {tab !== 'settings' ? <Definitions /> : null}
    </>
  )
}

function ActiveFilters({ filters, onClear }: { filters: Filters; onClear: (key: 'country' | 'device' | 'source' | 'path', value: null) => void }) {
  const { t } = useTranslation()
  const active = (['country', 'device', 'source', 'path'] as const).filter((key) => filters[key])
  if (active.length === 0) return null
  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="analytics-filters">
      <span className="text-12 text-app-muted">{t('analytics.filteredBy')}</span>
      {active.map((key) => (
        <button key={key} type="button" className={buttonClass('outline', 'sm')} onClick={() => onClear(key, null)} aria-label={t('analytics.removeFilter', { name: filters[key] })}>
          {key === 'country' && filters.country !== 'unknown' ? `${flag(filters.country)} ` : ''}
          {t(`analytics.filterNames.${key}`)}: {filters[key]} ✕
        </button>
      ))}
    </div>
  )
}

function Overview({ filters }: { filters: Filters }) {
  const { t } = useTranslation()
  const { number, percent } = useFormat()
  const summary = useSummary(filters)
  if (summary.isPending) return <Loading />
  if (summary.isError) return <ErrorNotice error={summary.error} />
  const { totals, previous, series, active, activeMinutes, countryCoverage } = summary.data.data
  const minutes = (seconds: number) => (seconds < 60 ? t('analytics.seconds', { n: number(seconds) }) : t('analytics.minutes', { n: number(Math.round((seconds / 60) * 10) / 10) }))

  return (
    <>
      <div className="grid-auto-fit-200 grid gap-3.5" data-testid="analytics-kpis">
        <Kpi testId="kpi-visitors" label={t('analytics.visitors')} value={number(totals.visitors)} current={totals.visitors} previous={previous.visitors} />
        <Kpi testId="kpi-sessions" label={t('analytics.sessions')} value={number(totals.sessions)} current={totals.sessions} previous={previous.sessions} />
        <Kpi testId="kpi-pageviews" label={t('analytics.pageViews')} value={number(totals.pageViews)} current={totals.pageViews} previous={previous.pageViews} />
        <Kpi testId="kpi-views-per-session" label={t('analytics.viewsPerSession')} value={number(totals.viewsPerSession)} current={totals.viewsPerSession} previous={previous.viewsPerSession} />
        <Kpi testId="kpi-engaged" label={t('analytics.engaged')} value={minutes(totals.engagedSeconds)} current={totals.engagedSeconds} previous={previous.engagedSeconds} />
        <Kpi testId="kpi-bounce" label={t('analytics.bounce')} value={totals.bounceRate === null ? '—' : percent(totals.bounceRate)} current={totals.bounceRate} previous={previous.bounceRate} lowerIsBetter />
        <Kpi testId="kpi-countries" label={t('analytics.countries')} value={number(totals.countries)} note={countryCoverage === null ? undefined : t('analytics.coverage', { percent: percent(countryCoverage) })} />
        <Kpi testId="kpi-conversions" label={t('analytics.conversions')} value={number(totals.conversions)} current={totals.conversions} previous={previous.conversions} note={totals.conversionRate === null ? undefined : t('analytics.conversionRate', { percent: percent(totals.conversionRate) })} />
        <Kpi testId="kpi-active" label={t('analytics.activeNow')} value={number(active)} note={t('analytics.activeNote', { minutes: number(activeMinutes) })} />
      </div>
      <Card>
        <CardTitle title={t('analytics.trend')} aside={<CsvButton report="daily" filters={filters} />} />
        {totals.sessions === 0 ? <EmptyState title={t('analytics.noData')} note={t('analytics.noDataNote')} /> : <TrendChart series={series} />}
      </Card>
    </>
  )
}

const sessionColumns = (t: (key: string) => string, number: (n: number) => string) => [
  { key: 'sessions', header: t('analytics.sessions'), align: 'right' as const, cell: (row: SessionRow) => number(row.sessions) },
  { key: 'visitors', header: t('analytics.visitors'), align: 'right' as const, cell: (row: SessionRow) => number(row.visitors) },
  { key: 'pageViews', header: t('analytics.pageViews'), align: 'right' as const, cell: (row: SessionRow) => number(row.pageViews) },
  { key: 'converting', header: t('analytics.columns.converting'), align: 'right' as const, cell: (row: SessionRow) => number(row.convertingSessions) },
]

function Locations({ filters, onCountry }: { filters: Filters; onCountry: (code: string) => void }) {
  const { t } = useTranslation()
  const { number, percent } = useFormat()
  // The map shows every country of the period at once (500 is more than there are).
  const countries = useBreakdown<SessionRow>('country', { ...filters, country: undefined }, 1, 500)
  const summary = useSummary(filters)
  const values = Object.fromEntries((countries.data?.data.rows ?? []).filter((row) => row.key.country_code).map((row) => [row.key.country_code as string, row.sessions]))
  const columns = sessionColumns(t, number)
  const pick = (row: SessionRow) => onCountry(row.key.country_code ?? 'unknown')

  return (
    <>
      <Card>
        <CardTitle title={t('analytics.map')} />
        <WorldMap values={values} onPick={onCountry} />
        {summary.data?.data.countryCoverage != null ? <p className="m-0 text-12 text-app-muted">{t('analytics.locationNote', { percent: percent(summary.data.data.countryCoverage) })}</p> : null}
      </Card>
      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <BreakdownCard<SessionRow> title={t('analytics.reports.country')} dimension="country" filters={filters} columns={columns} onPick={pick} testId="analytics-countries" />
        <BreakdownCard<SessionRow> title={t('analytics.reports.city')} dimension="city" filters={filters} columns={columns.slice(0, 2)} testId="analytics-cities" emptyNote={t('analytics.cityNote')} />
        <BreakdownCard<SessionRow> title={t('analytics.reports.region')} dimension="region" filters={filters} columns={columns.slice(0, 2)} testId="analytics-regions" emptyNote={t('analytics.cityNote')} />
      </div>
    </>
  )
}

function Pages({ filters, onPage }: { filters: Filters; onPage: (page: string) => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const sessions = sessionColumns(t, number).slice(0, 2)
  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
      <BreakdownCard<EventRow>
        title={t('analytics.reports.page')}
        dimension="page"
        filters={filters}
        testId="analytics-pages"
        onPick={(row) => onPage(row.label)}
        columns={[
          { key: 'views', header: t('analytics.pageViews'), align: 'right', cell: (row) => number(row.events) },
          { key: 'sessions', header: t('analytics.sessions'), align: 'right', cell: (row) => number(row.sessions) },
        ]}
      />
      <BreakdownCard<EventRow>
        title={t('analytics.reports.tour')}
        dimension="tour"
        filters={filters}
        testId="analytics-tours"
        columns={[
          { key: 'views', header: t('analytics.columns.views'), align: 'right', cell: (row) => number(row.views) },
          { key: 'started', header: t('analytics.columns.started'), align: 'right', cell: (row) => number(row.bookingsStarted) },
          { key: 'booked', header: t('analytics.columns.booked'), align: 'right', cell: (row) => number(row.bookings) },
        ]}
      />
      <BreakdownCard<SessionRow> title={t('analytics.reports.landing')} dimension="landing" filters={filters} columns={sessions} onPick={(row) => onPage(row.label)} testId="analytics-landing" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.exit')} dimension="exit" filters={filters} columns={sessions} testId="analytics-exit" />
    </div>
  )
}

function Sources({ filters, onSource }: { filters: Filters; onSource: (source: string) => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const columns = sessionColumns(t, number)
  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
      <BreakdownCard<SessionRow> title={t('analytics.reports.source')} dimension="source" filters={filters} columns={columns} onPick={(row) => onSource(row.key.source ?? '')} testId="analytics-sources" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.referrer')} dimension="referrer" filters={filters} columns={columns} testId="analytics-referrers" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.campaign')} dimension="campaign" filters={filters} columns={columns} testId="analytics-campaigns" emptyNote={t('analytics.campaignNote')} />
    </div>
  )
}

function Devices({ filters, onDevice }: { filters: Filters; onDevice: (device: string) => void }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const columns = sessionColumns(t, number)
  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
      <BreakdownCard<SessionRow> title={t('analytics.reports.device')} dimension="device" filters={filters} columns={columns} onPick={(row) => onDevice(row.key.device ?? '')} testId="analytics-devices" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.browser')} dimension="browser" filters={filters} columns={columns} testId="analytics-browsers" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.os')} dimension="os" filters={filters} columns={columns} testId="analytics-os" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.language')} dimension="language" filters={filters} columns={columns} testId="analytics-languages" />
    </div>
  )
}

function Conversions({ filters }: { filters: Filters }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const columns = sessionColumns(t, number)
  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
      <BreakdownCard<EventRow>
        title={t('analytics.reports.conversion')}
        dimension="conversion"
        filters={filters}
        testId="analytics-conversions"
        emptyNote={t('analytics.conversionNote')}
        columns={[
          { key: 'events', header: t('analytics.columns.count'), align: 'right', cell: (row) => number(row.events) },
          { key: 'sessions', header: t('analytics.sessions'), align: 'right', cell: (row) => number(row.sessions) },
        ]}
      />
      <BreakdownCard<SessionRow> title={t('analytics.reports.conversionBySource')} dimension="source" filters={filters} columns={[columns[0], columns[3]]} testId="analytics-conversion-sources" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.conversionByCountry')} dimension="country" filters={filters} columns={[columns[0], columns[3]]} testId="analytics-conversion-countries" />
      <BreakdownCard<SessionRow> title={t('analytics.reports.campaign')} dimension="campaign" filters={filters} columns={[columns[0], columns[3]]} testId="analytics-conversion-campaigns" emptyNote={t('analytics.campaignNote')} />
    </div>
  )
}

function LiveTab() {
  const { t } = useTranslation()
  const { number, dateTime } = useFormat()
  const live = useLive()
  if (live.isPending) return <Loading />
  if (live.isError) return <ErrorNotice error={live.error} />
  const { sessions, recent, activeMinutes } = live.data.data
  const place = (row: { countryName: string | null; country: string | null; city: string | null }) => (row.country ? `${flag(row.country)} ${[row.city, row.countryName].filter(Boolean).join(', ')}` : t('analytics.unknownPlace'))

  return (
    <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line px-4 py-3">
          <CardTitle title={t('analytics.liveTitle', { count: sessions.length, n: number(sessions.length) })} aside={<span className="text-12 text-app-muted">{t('analytics.liveNote', { minutes: number(activeMinutes) })}</span>} />
        </div>
        {sessions.length === 0 ? (
          <EmptyState title={t('analytics.nobodyLive')} />
        ) : (
          <ul className="m-0 flex list-none flex-col p-0" data-testid="analytics-live">
            {sessions.map((session) => (
              <li key={session.startedAt + session.landing} className="flex flex-col gap-0.5 border-t border-app-line px-4 py-2.5 text-13 first:border-t-0">
                <span className="font-medium">{place(session)} · {t(`analytics.devices.${session.device}`)}</span>
                <span className="text-12 text-app-muted">
                  {t('analytics.liveRow', { page: session.page, landing: session.landing, views: number(session.pageViews), source: t(`analytics.sources.${session.source}`) })}
                </span>
                <span className="text-12 text-app-muted">{t('analytics.liveTimes', { started: dateTime(session.startedAt), seen: dateTime(session.lastSeenAt) })}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line px-4 py-3"><CardTitle title={t('analytics.recent')} /></div>
        {recent.length === 0 ? (
          <EmptyState title={t('analytics.noData')} />
        ) : (
          <ul className="m-0 flex list-none flex-col p-0" data-testid="analytics-recent">
            {recent.map((event, i) => (
              <li key={`${event.at}-${i}`} className="flex flex-wrap items-baseline justify-between gap-x-3 border-t border-app-line px-4 py-2 text-13 first:border-t-0">
                <span className="min-w-0 truncate">
                  {event.type === 'page_view' ? event.page : <strong>{t(`analytics.conversionTypes.${event.type}`)}</strong>}
                </span>
                <span className="text-12 text-app-muted">{place(event)} · {dateTime(event.at)}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

function SettingsTab() {
  const { t } = useTranslation()
  const settings = useAnalyticsSettings(true)
  if (settings.isPending) return <Loading />
  if (settings.isError) return <ErrorNotice error={settings.error} />
  return <SettingsForm key={JSON.stringify(settings.data.data)} initial={settings.data.data} title={t('analytics.tabs.settings')} />
}

function SettingsForm({ initial, title }: { initial: AnalyticsSettings; title: string }) {
  const { t } = useTranslation()
  const toast = useToast()
  const save = useSaveAnalyticsSettings()
  const [value, setValue] = useState(initial)
  const [paths, setPaths] = useState(initial.excludedPaths.join('\n'))
  const error = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)

  return (
    <Card>
      <CardTitle title={title} />
      <Switch label={t('analytics.settings.enabled')} checked={value.enabled} onChange={(enabled) => setValue({ ...value, enabled })} hint={t('analytics.settings.enabledHint')} />
      <div className="grid-auto-fit-200 grid gap-3">
        <NumberInput label={t('analytics.settings.retention')} value={value.retentionMonths} onChange={(retentionMonths) => setValue({ ...value, retentionMonths: retentionMonths ?? 13 })} error={error('retentionMonths')} hint={t('analytics.settings.retentionHint')} />
        <NumberInput label={t('analytics.settings.active')} value={value.activeMinutes} onChange={(activeMinutes) => setValue({ ...value, activeMinutes: activeMinutes ?? 5 })} error={error('activeMinutes')} hint={t('analytics.settings.activeHint')} />
        <NumberInput label={t('analytics.settings.session')} value={value.sessionMinutes} onChange={(sessionMinutes) => setValue({ ...value, sessionMinutes: sessionMinutes ?? 30 })} error={error('sessionMinutes')} hint={t('analytics.settings.sessionHint')} />
      </div>
      <TextArea label={t('analytics.settings.excluded')} value={paths} onChange={setPaths} rows={3} error={error('excludedPaths') ?? error('excludedPaths.0')} hint={t('analytics.settings.excludedHint')} />

      {/* Google Analytics 4 (docs/visitor-analytics.md §GA4): the website loads it for visitors who accept cookies. */}
      <div className="flex flex-col gap-2.5 rounded-12 border border-app-line p-3.5" data-testid="ga4-settings">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="m-0 text-14 font-semibold">{t('analytics.ga4.title')}</h3>
          <Badge tone={initial.ga4MeasurementId ? 'green' : 'slate'}>{initial.ga4MeasurementId ? t('analytics.ga4.connected', { id: initial.ga4MeasurementId }) : t('analytics.ga4.notConnected')}</Badge>
        </div>
        <ol className="m-0 flex flex-col gap-1 pl-5 text-12.5 leading-1.55 text-app-muted">
          {(t('analytics.ga4.steps', { returnObjects: true }) as string[]).map((step) => (
            <li key={step}>{step}</li>
          ))}
        </ol>
        <TextInput
          label={t('analytics.ga4.measurementId')}
          value={value.ga4MeasurementId ?? ''}
          onChange={(id) => setValue({ ...value, ga4MeasurementId: id.trim() === '' ? null : id.trim() })}
          error={error('ga4MeasurementId')}
          placeholder="G-XXXXXXXXXX"
          hint={t('analytics.ga4.hint')}
          autoComplete="off"
        />
        {initial.ga4MeasurementId ? (
          <a className="self-start text-13 font-semibold text-blue" href="https://analytics.google.com/analytics/web/#/realtime" target="_blank" rel="noopener noreferrer">
            {t('analytics.ga4.openRealtime')}
          </a>
        ) : null}
      </div>
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <button
        type="button"
        className={buttonClass('primary', 'md', 'self-start')}
        disabled={save.isPending}
        onClick={() =>
          save.mutate(
            { ...value, excludedPaths: paths.split('\n').map((line) => line.trim()).filter(Boolean) },
            { onSuccess: () => toast(t('common.saved')) },
          )
        }
      >
        {save.isPending ? t('common.saving') : t('common.save')}
      </button>
    </Card>
  )
}

/** What each figure means (docs/visitor-analytics.md), under every report. */
function Definitions() {
  const { t } = useTranslation()
  return (
    <details className="rounded-12 border border-app-line bg-app-surface px-4 py-3 text-13 print:hidden">
      <summary className="cursor-pointer font-semibold">{t('analytics.definitions.title')}</summary>
      <dl className="m-0 mt-2 grid gap-2">
        {(['visitors', 'sessions', 'pageViews', 'engaged', 'bounce', 'active', 'conversions', 'location', 'privacy'] as const).map((key) => (
          <div key={key}>
            <dt className="font-semibold">{t(`analytics.definitions.${key}.term`)}</dt>
            <dd className="m-0 text-app-muted">{t(`analytics.definitions.${key}.text`)}</dd>
          </div>
        ))}
      </dl>
    </details>
  )
}
