import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { api, fetchDocument } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import type { BookingSummary } from '../bookings/api'
import { BookingStatusBadge, PaymentBadge } from '../bookings/badges'

/** api/app/Http/Controllers/Api/V1/Admin/DashboardController.php — a widget the staff member may not see is absent. */
type Alert =
  | { kind: 'payments_review' | 'leads_unanswered' | 'notifications_failed'; count: number; link: string }
  | { kind: 'passports_missing'; count: number; link: string; reference: string; date: string; package_en: string; package_bn: string | null; names: string[] }
  | { kind: 'whatsapp_disconnected'; status: string; checked_at: string; link: string }

type Dashboard = {
  today: string
  month: string
  departures: { count: number; next_date: string | null; seats_left: number; groups: number }
  upcoming: { id: number; package_id: number; title_en: string | null; title_bn: string | null; date: string; seats_total: number | null; booked: number | null; seats_left: number | null }[]
  alerts: Alert[]
  collected?: { amount: number; invoiced: number }
  by_destination?: { destination_id: number | null; name_en: string | null; name_bn: string | null; amount: number }[]
  bookings?: { confirmed: number; previous: number; awaiting_payment: number }
  passports_missing?: number
  recent_bookings?: BookingSummary[]
  leads?: { new: number; waiting_over_24h: number }
}

/**
 * The Dashboard (docs/phase-5-admin-core.md §4.2): the prototype's six figures, alerts, upcoming departures and money by
 * destination, plus the recent bookings the README asks for. Nothing is a literal; every figure links to where it comes from.
 */
export function DashboardPage() {
  const { t } = useTranslation()
  const { weekdayDate } = useFormat()
  const dashboard = useQuery({ queryKey: ['dashboard'], queryFn: ({ signal }) => api.get<Data<Dashboard>>('admin/dashboard', signal).then((r) => r.data), refetchInterval: 60_000 })

  return (
    <>
      <PageHeader title={t('dashboard.title')} subtitle={weekdayDate()} />
      {dashboard.isPending ? <Loading /> : dashboard.isError ? <ErrorNotice error={dashboard.error} /> : <Widgets data={dashboard.data} />}
    </>
  )
}

function Widgets({ data }: { data: Dashboard }) {
  const { t } = useTranslation()
  const { bdt, bdtCompact, number, date } = useFormat()
  const change = data.bookings ? data.bookings.confirmed - data.bookings.previous : 0

  return (
    <>
      <div className="grid-auto-fit-180 grid gap-3.5" data-testid="dashboard-kpis">
        {data.collected ? (
          <Kpi testId="kpi-collected" label={t('dashboard.collected')} value={bdtCompact(data.collected.amount)} title={bdt(data.collected.amount)} note={t('dashboard.invoiced', { amount: bdtCompact(data.collected.invoiced) })} to="/transactions" />
        ) : null}
        {data.bookings ? (
          <Kpi
            testId="kpi-bookings"
            label={t('dashboard.bookings')}
            value={number(data.bookings.confirmed)}
            note={`${t('dashboard.change', { sign: change > 0 ? '+' : change < 0 ? '−' : '±', n: number(Math.abs(change)) })} · ${t('dashboard.awaiting', { n: number(data.bookings.awaiting_payment) })}`}
            tone={change > 0 ? 'green' : undefined}
            to="/bookings?status=confirmed"
          />
        ) : null}
        <Kpi testId="kpi-departures" label={t('dashboard.departures')} value={number(data.departures.count)} note={data.departures.next_date ? t('dashboard.next', { date: date(data.departures.next_date) }) : t('dashboard.noDepartures')} />
        {data.leads ? (
          <Kpi
            testId="kpi-leads"
            label={t('dashboard.newLeads')}
            value={number(data.leads.new)}
            note={t('dashboard.waiting', { n: number(data.leads.waiting_over_24h) })}
            tone={data.leads.waiting_over_24h > 0 ? 'amber' : undefined}
            to="/customers?state=new"
          />
        ) : null}
        <Kpi testId="kpi-seats" label={t('dashboard.seatsLeft')} value={number(data.departures.seats_left)} note={t('dashboard.acrossGroups', { count: data.departures.groups, n: number(data.departures.groups) })} />
        {data.passports_missing !== undefined ? (
          <Kpi testId="kpi-passports" label={t('dashboard.passportsMissing')} value={number(data.passports_missing)} note={t('dashboard.upcomingTrips')} tone={data.passports_missing > 0 ? 'amber' : undefined} />
        ) : null}
      </div>

      <div className="grid-auto-fit-320 grid items-start gap-4.5">
        <AlertsCard alerts={data.alerts} />
        <DeparturesCard data={data} />
        {data.by_destination ? <DestinationsCard rows={data.by_destination} /> : null}
      </div>

      {data.recent_bookings ? <RecentBookings rows={data.recent_bookings} /> : null}
    </>
  )
}

function Kpi({ label, value, note, tone, to, title, testId }: { label: string; value: string; note: string; tone?: 'green' | 'amber'; to?: string; title?: string; testId: string }) {
  const noteClass = tone === 'green' ? 'text-green' : tone === 'amber' ? 'text-amber' : 'text-app-muted'
  const body: ReactNode = (
    <>
      <span className="text-13 text-app-muted">{label}</span>
      <span className="font-display text-28 font-extrabold tracking-heading" title={title}>
        {value}
      </span>
      <span className={`text-12 ${noteClass}`}>{note}</span>
    </>
  )
  const className = 'flex flex-col gap-1.5 rounded-16 border border-app-line bg-app-surface px-5 py-4.5 text-app-text no-underline'
  return to ? (
    <Link to={to} className={`${className} hover:border-blue`} data-testid={testId}>
      {body}
    </Link>
  ) : (
    <div className={className} data-testid={testId}>
      {body}
    </div>
  )
}

function AlertsCard({ alerts }: { alerts: Alert[] }) {
  const { t } = useTranslation()
  const { number, date, dateTime } = useFormat()

  const describe = (alert: Alert): { text: string; meta: string; color: string } => {
    switch (alert.kind) {
      case 'passports_missing':
        return {
          text: t('dashboard.alert.passports', { count: alert.count, n: number(alert.count), reference: alert.reference }),
          meta: `${alert.package_en || alert.package_bn} · ${t('dashboard.departs', { date: date(alert.date) })} · ${alert.names.join(', ')}`,
          color: 'bg-red',
        }
      case 'whatsapp_disconnected':
        return { text: t('dashboard.alert.whatsapp', { status: alert.status }), meta: t('dashboard.checkedAt', { when: dateTime(alert.checked_at) }), color: 'bg-red' }
      case 'payments_review':
        return { text: t('dashboard.alert.review', { count: alert.count, n: number(alert.count) }), meta: t('dashboard.alert.reviewMeta'), color: 'bg-orange' }
      case 'leads_unanswered':
        return { text: t('dashboard.alert.leads', { count: alert.count, n: number(alert.count) }), meta: t('dashboard.alert.leadsMeta'), color: 'bg-amber' }
      case 'notifications_failed':
        return { text: t('dashboard.alert.failed', { count: alert.count, n: number(alert.count) }), meta: t('dashboard.alert.failedMeta'), color: 'bg-red' }
    }
  }

  return (
    <Card>
      <CardTitle title="Alerts" />
      {alerts.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('dashboard.noAlerts')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="dashboard-alerts">
          {alerts.map((alert, index) => {
            const { text, meta, color } = describe(alert)
            return (
              <li key={`${alert.kind}-${index}`}>
                <Link to={alert.link} className="flex items-start gap-3 rounded-10 bg-app-surface-2 px-3 py-2.5 text-app-text no-underline hover:ring-1 hover:ring-blue">
                  <span className={`mt-1.75 size-2 shrink-0 rounded-full ${color}`} aria-hidden />
                  <span className="flex flex-1 flex-col leading-1.35">
                    <span className="text-14 font-medium">{text}</span>
                    <span className="text-12 text-app-muted">{meta}</span>
                  </span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

function DeparturesCard({ data }: { data: Dashboard }) {
  const { t } = useTranslation()
  const { number, date } = useFormat()

  return (
    <Card>
      <CardTitle title="Upcoming departures · seats left" />
      {data.upcoming.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('dashboard.noDepartures')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col p-0" data-testid="dashboard-departures">
          {data.upcoming.map((departure) => {
            const percent = departure.seats_total ? Math.round(((departure.booked ?? 0) / departure.seats_total) * 100) : 0
            return (
              <li key={departure.id} className="flex flex-col gap-1.5 border-b border-app-line py-2.5 last:border-b-0">
                <span className="flex justify-between gap-2.5 text-14">
                  <Link to={`/packages/${departure.package_id}`} className="truncate font-medium text-app-text">
                    {departure.title_en || departure.title_bn}
                  </Link>
                  <span className="font-display whitespace-nowrap text-app-muted">{date(departure.date)}</span>
                </span>
                {departure.seats_total !== null ? (
                  <span className="flex items-center gap-2.5">
                    <span className="h-1.5 flex-1 overflow-hidden rounded-3 bg-app-line">
                      <span className="block h-full bg-linear-90 from-blue to-orange" style={{ width: `${percent}%` }} />
                    </span>
                    <span className="text-12 whitespace-nowrap text-app-muted">
                      {t('dashboard.seats', { booked: number(departure.booked ?? 0), total: number(departure.seats_total), left: number(departure.seats_left ?? 0) })}
                    </span>
                  </span>
                ) : (
                  <span className="text-12 text-app-muted">{t('dashboard.noSeatLimit')}</span>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

function DestinationsCard({ rows }: { rows: NonNullable<Dashboard['by_destination']> }) {
  const { t } = useTranslation()
  const { bdtCompact, bdt } = useFormat()
  const top = Math.max(1, ...rows.map((row) => row.amount))

  return (
    <Card>
      <CardTitle title="Collected by destination" aside={<span className="text-12 text-app-muted">{t('dashboard.thisMonth')}</span>} />
      {rows.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('dashboard.nothingCollected')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="dashboard-destinations">
          {rows.map((row) => (
            <li key={row.destination_id ?? 'other'} className="grid grid-cols-[minmax(72px,0.8fr)_minmax(90px,1fr)_minmax(72px,0.8fr)] items-center gap-2.5 text-14">
              <span className="truncate">{row.destination_id === null ? t('dashboard.other') : (row.name_en || row.name_bn) || row.name_en}</span>
              <span className="h-2.5 overflow-hidden rounded-5 bg-app-line">
                <span className="block h-full bg-blue" style={{ width: `${Math.max(0, Math.round((row.amount / top) * 100))}%` }} />
              </span>
              <span className="text-right font-display text-app-muted" title={bdt(row.amount)}>
                {bdtCompact(row.amount)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function RecentBookings({ rows }: { rows: BookingSummary[] }) {
  const { t } = useTranslation()
  const { bdt, date, number, locale } = useFormat()
  const navigate = useNavigate()
  const toast = useToast()

  const openPdf = async (booking: BookingSummary) => {
    try {
      const blob = await fetchDocument(`admin/bookings/${booking.id}/invoice/pdf?lang=${locale}`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('bookings.pdfFailed'), 'error')
    }
  }

  const actionsFor = (booking: BookingSummary): RowAction[] => [
    ...contactActions(t, { phone: booking.customer?.phone, email: booking.customer?.email, subject: t('bookings.contactSubject', { reference: booking.reference }) }),
    { key: 'pdf', icon: '⎙', label: t('table.pdf'), tone: 'amber', onSelect: () => void openPdf(booking), disabledReason: booking.has_invoice ? undefined : t('bookings.noInvoiceYet') },
    { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', to: `/bookings/${booking.id}` },
  ]

  const columns: Column<BookingSummary>[] = [
    { key: 'reference', header: t('bookings.reference'), cell: (b) => <Link to={`/bookings/${b.id}`} className="font-display font-semibold">{b.reference}</Link> },
    {
      key: 'customer',
      header: t('bookings.customer'),
      cell: (b) => (
        <div className="flex max-w-64 flex-col">
          <span className="font-medium">{b.customer?.name ?? '—'}</span>
          <span className="truncate text-12 text-app-muted">
            {b.package_title_en || b.package_title_bn} · {b.travel_start ? date(b.travel_start) : '—'} · {t('bookings.paxCount', { count: b.pax_count, n: number(b.pax_count) })}
          </span>
        </div>
      ),
    },
    { key: 'total', header: t('bookings.total'), align: 'right', className: 'font-display font-semibold whitespace-nowrap', cell: (b) => bdt(b.total_amount) },
    {
      key: 'status',
      header: t('common.status'),
      cell: (b) => (
        <span className="flex flex-wrap gap-1.5">
          <BookingStatusBadge status={b.status} />
          <PaymentBadge status={b.payment_status} />
        </span>
      ),
    },
  ]

  return (
    <Card padded={false} className="overflow-hidden">
      <div className="flex items-baseline justify-between gap-2 border-b border-app-line px-4.5 py-3.5">
        <CardTitle title="Recent bookings" />
        <Link to="/bookings" className="text-13">
          {t('dashboard.allBookings')}
        </Link>
      </div>
      {rows.length === 0 ? (
        <p className="m-0 p-4.5 text-13 text-app-muted">{t('bookings.empty')}</p>
      ) : (
        <DataTable label={t('dashboard.recentBookings')} testId="recent-bookings-table" columns={columns} rows={rows} rowKey={(b) => b.id} rowLabel={(b) => b.reference} actions={actionsFor} onRowClick={(b) => navigate(`/bookings/${b.id}`)} />
      )}
    </Card>
  )
}
