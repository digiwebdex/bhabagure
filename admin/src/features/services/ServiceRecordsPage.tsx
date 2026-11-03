import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { SERVICE_FILTERS, statusTone, useServiceRecords, type ServiceFilter, type ServiceKind, type ServiceRow } from './api'
import { ServiceStatusDialog } from './ServiceStatusDialog'

const PATH: Record<ServiceKind, string> = { air: '/air-ticketing', hotel: '/hotel-requests' }

/** Sold tickets or bookings on one side, the website's enquiries on the other; Air ticketing and Hotel requests share this. */
export function ServiceTabs({ kind, active }: { kind: ServiceKind; active: 'sold' | 'enquiries' }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const tabs = [
    { key: 'sold', label: t(`serviceRecords.${kind}.tab`), to: PATH[kind] },
    { key: 'enquiries', label: t(`serviceRecords.${kind}.enquiriesTab`), to: `${PATH[kind]}?state=open` },
  ] as const

  return (
    <div className="flex flex-wrap gap-1 self-start rounded-pill bg-linear-90/srgb from-blue to-blue-abyss p-1" role="tablist" aria-label={t(`${kind}.title`)}>
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          role="tab"
          aria-selected={active === tab.key}
          onClick={() => navigate(tab.to)}
          className={`cursor-pointer rounded-pill px-5 py-1.75 text-13 font-semibold transition-colors duration-150 ${active === tab.key ? 'bg-app-surface text-app-text' : 'bg-transparent text-white/85 hover:text-white'}`}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}

/**
 * Air ticketing → Tickets, Hotel requests → Bookings (docs/invoice-services.md): what issued invoices sold, the next
 * departure on top, with what is leaving soon flagged. The status changes here or on the invoice; WhatsApp opens the
 * staff member's own, with the new schedule or the ticket's details ready to send.
 */
export function ServiceRecordsPage({ kind }: { kind: ServiceKind }) {
  const { t } = useTranslation()
  const { date, digits, number } = useFormat()
  const [params, setParams] = useSearchParams()
  const allowed = SERVICE_FILTERS[kind] as readonly ServiceFilter[]
  const filter = allowed.includes(params.get('filter') as ServiceFilter) ? (params.get('filter') as ServiceFilter) : 'upcoming'
  const search = params.get('search') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const list = useServiceRecords(kind, { filter, search, page })
  const [editing, setEditing] = useState<ServiceRow | null>(null)
  const air = kind === 'air'
  const counts = list.data?.meta.counts

  const set = (patch: { filter?: ServiceFilter; search?: string; page?: number }) => {
    const next = { filter, search, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.filter !== 'upcoming') query.set('filter', next.filter)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const filterLabel = (value: ServiceFilter) => {
    const count = value === 'soon' ? counts?.soon : value === 'changed' ? counts?.changed : undefined
    return count ? `${t(`serviceRecords.filters.${value}`)} (${number(count)})` : t(`serviceRecords.filters.${value}`)
  }

  const dates = (row: ServiceRow) => [row.starts_on ? date(row.starts_on) : null, row.ends_on ? date(row.ends_on) : null].filter(Boolean).join(' – ')

  const columns: Column<ServiceRow>[] = [
    {
      key: 'passenger',
      header: air ? t('serviceRecords.columns.passenger') : t('serviceRecords.columns.guest'),
      cell: (row) => (
        <div className="flex flex-col items-start gap-0.5">
          <span className="font-medium">{row.passenger_name ?? row.customer_name ?? '—'}</span>
          {row.passenger_name && row.passenger_name !== row.customer_name ? <span className="text-12 text-app-muted">{row.customer_name}</span> : null}
          {row.phone ? <span className="font-display text-12 text-app-muted">{digits(row.phone.replace(/^88/, ''))}</span> : null}
          {row.departure_soon ? (
            <span data-testid="departure-soon">
              <Badge tone={row.departure_urgent ? 'red' : 'orange'}>
                {row.days_left === 0 ? t('serviceRecords.departsToday') : t('serviceRecords.departureSoon', { count: row.days_left ?? 0, n: number(row.days_left ?? 0) })}
              </Badge>
            </span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'what',
      header: air ? t('serviceRecords.columns.route') : t('serviceRecords.columns.hotel'),
      cell: (row) => (
        <div className="flex max-w-72 flex-col">
          <span className="truncate font-medium">{air ? `${row.route_from} → ${row.route_to}` : row.hotel_name}</span>
          {!air && row.room_type ? <span className="text-12 text-app-muted">{row.room_type}</span> : null}
          <span className="text-12 text-app-muted">
            {dates(row)}
            {air && row.trip_type ? ` · ${t(`serviceRecords.tripTypes.${row.trip_type}`)}` : ''}
          </span>
        </div>
      ),
    },
    {
      key: 'reference',
      header: air ? t('serviceRecords.columns.pnr') : t('serviceRecords.columns.reservation'),
      cell: (row) => (row.reference ? <span className="font-display text-13 font-semibold">{row.reference}</span> : <span className="text-12 text-app-muted">{t('serviceRecords.noReference')}</span>),
    },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) => (
        <span className="flex max-w-60 flex-col items-start gap-0.5" data-testid="service-status">
          {row.cancelled ? <Badge tone="slate">{t('serviceRecords.invoiceCancelled')}</Badge> : <Badge tone={statusTone(row.status)}>{t(`serviceRecords.statuses.${row.status}`)}</Badge>}
          {row.status === 'schedule_changed' && row.schedule_note ? <span className="line-clamp-2 text-12 text-app-muted">{row.schedule_note}</span> : null}
        </span>
      ),
    },
    {
      key: 'invoice',
      header: t('serviceRecords.columns.invoice'),
      cell: (row) =>
        row.invoice.number ? (
          <Link to={`/invoices?search=${encodeURIComponent(row.invoice.number)}`} onClick={(event) => event.stopPropagation()} className="font-display text-13">
            {row.invoice.number}
          </Link>
        ) : (
          '—'
        ),
    },
  ]

  const actionsFor = (row: ServiceRow): RowAction[] => [
    {
      key: 'update',
      icon: '✎',
      label: t('serviceRecords.update'),
      tone: 'blue',
      onSelect: () => setEditing(row),
      disabledReason: row.cancelled ? t('serviceRecords.invoiceCancelled') : row.can_update ? undefined : t('serviceRecords.noPermission'),
    },
    ...contactActions(t, { phone: row.phone, channels: ['whatsapp'], message: row.whatsapp_text }),
  ]

  return (
    <>
      <PageHeader title={t(`${kind}.title`)} subtitle={t(`serviceRecords.${kind}.subtitle`)} />
      <ServiceTabs kind={kind} active="sold" />
      <Chips label={t('common.status')} value={filter} onChange={(value) => set({ filter: value })} options={allowed.map((value) => ({ value, label: filterLabel(value) }))} />

      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line p-3.5">
          <input type="search" value={search} onChange={(event) => set({ search: event.target.value })} placeholder={t(`serviceRecords.${kind}.search`)} aria-label={t(`serviceRecords.${kind}.search`)} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t(`serviceRecords.${kind}.empty`)} note={t(`serviceRecords.${kind}.emptyNote`)} />
        ) : (
          <DataTable
            label={t(`${kind}.title`)}
            testId={`${kind}-services-table`}
            columns={columns}
            rows={list.data.data}
            rowKey={(row) => row.id}
            rowLabel={(row) => row.passenger_name ?? row.customer_name ?? row.reference ?? String(row.id)}
            actions={actionsFor}
            onRowClick={(row) => {
              if (row.can_update) setEditing(row)
            }}
          />
        )}
        {list.data && list.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={page <= 1} onClick={() => set({ page: page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: number(page), last: number(list.data.meta.last_page) })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={page >= list.data.meta.last_page} onClick={() => set({ page: page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>
      {editing ? <ServiceStatusDialog key={editing.id} record={editing} onClose={() => setEditing(null)} /> : null}
    </>
  )
}
