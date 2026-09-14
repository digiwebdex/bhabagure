import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { airActions, useAirAction, useAirInquiries, type AirFilters, type AirInquiry } from './api'

const STATES = ['open', 'quoted', 'all'] as const
const OWNERS = ['all', 'mine', 'pool'] as const

/** Filters live in the URL: the sidebar badge opens ?state=open&stale=1, exactly the rows it counts. */
function readFilters(params: URLSearchParams): AirFilters {
  const pick = <T extends string>(value: string | null, allowed: readonly T[], fallback: T): T => (allowed.includes(value as T) ? (value as T) : fallback)
  return {
    state: pick(params.get('state'), STATES, 'open'),
    stale: params.get('stale') === '1',
    owner: pick(params.get('owner'), OWNERS, 'all'),
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/**
 * Air ticketing — the enquiry queue only (docs/phase-5-admin-core.md §4.7). The website's air-ticket enquiries, oldest
 * open ones first, flagged after 24 hours. Staff reply from their own WhatsApp or email (no SMS) and mark the enquiry
 * quoted; fares, PNRs and airline commission come with the rest of air ticketing.
 */
export function AirTicketingPage() {
  const { t } = useTranslation()
  const { number, date, relativeAge, digits } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useAirInquiries(filters)
  const [viewing, setViewing] = useState<AirInquiry | null>(null)
  const claim = useAirAction((id: number) => airActions.claim(id))
  const quote = useAirAction((id: number) => airActions.markQuoted(id))
  const undo = useAirAction((id: number) => airActions.undoQuoted(id))
  const seesAll = can('bookings.view_all')

  const set = (patch: Partial<AirFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams({ state: next.state })
    if (next.stale) query.set('stale', '1')
    if (next.owner !== 'all') query.set('owner', next.owner)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const route = (row: AirInquiry) => `${row.from ?? '?'} → ${row.to ?? '?'}`

  const actionsFor = (row: AirInquiry): RowAction[] => {
    // As on Bookings: someone who doesn't see every enquiry claims a pool one before contacting the customer.
    const claimFirst = !seesAll && row.assigned_staff === null ? t('air.claimFirst') : undefined
    return [
      { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', onSelect: () => setViewing(row) },
      ...contactActions(t, {
        phone: row.phone,
        email: row.email,
        channels: ['whatsapp', 'email'],
        subject: t('air.contactSubject', { route: route(row) }),
        message: t('air.contactMessage', { name: row.name, route: route(row), date: row.depart_on ?? '' }),
      }).map((action) => ({ ...action, disabledReason: claimFirst ?? action.disabledReason })),
      row.status === 'new'
        ? {
            key: 'quoted',
            icon: '✓',
            label: t('air.markQuoted'),
            tone: 'green',
            onSelect: () => quote.mutate(row.id, { onSuccess: () => toast(t('air.quoted', { name: row.name })), onError: (error) => toast(error.message, 'error') }),
            disabledReason: row.actions.mark_quoted ? undefined : t('air.noPermission'),
          }
        : {
            key: 'quoted',
            icon: '↺',
            label: t('air.undoQuoted'),
            tone: 'amber',
            onSelect: () => undo.mutate(row.id, { onSuccess: () => toast(t('air.reopened', { name: row.name })), onError: (error) => toast(error.message, 'error') }),
            disabledReason: row.actions.undo_quoted ? undefined : t('air.undoOnlyBy'),
          },
    ]
  }

  const columns: Column<AirInquiry>[] = [
    {
      key: 'passenger',
      header: t('air.passenger'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-medium">{row.name}</span>
          <span className="font-display text-12 text-app-muted">{digits(row.phone.replace(/^88/, ''))}</span>
          {row.stale ? (
            <span className="self-start rounded-pill bg-red-tint px-2 py-0.25 text-11 font-semibold text-red" data-testid="stale-flag">
              {t('air.waiting', { age: relativeAge(row.age_hours * 60) })}
            </span>
          ) : (
            <span className="text-12 text-app-muted">{relativeAge(Math.floor((Date.now() - Date.parse(row.created_at)) / 60_000))}</span>
          )}
        </div>
      ),
    },
    {
      key: 'route',
      header: t('air.route'),
      cell: (row) => (
        <div className="flex max-w-72 flex-col">
          <span className="truncate font-medium">{route(row)}</span>
          <span className="text-12 text-app-muted">
            {row.depart_on ? date(row.depart_on) : '—'}
            {row.return_on ? ` – ${date(row.return_on)}` : ` · ${t('air.oneWay')}`} · {t('air.passengers', { count: row.passengers ?? 1, n: number(row.passengers ?? 1) })} · {t(`air.cabin.${row.cabin_class ?? 'economy'}`)}
          </span>
        </div>
      ),
    },
    {
      key: 'owner',
      header: t('ownership.owner'),
      cell: (row) =>
        row.assigned_staff ? (
          <span className="text-13">{row.assigned_staff.name}</span>
        ) : (
          <span className="flex items-center gap-1.5">
            <span title={t('ownership.poolNote')} className="rounded-pill bg-purple-tint px-2 py-0.25 text-11 font-semibold text-purple">
              {t('ownership.pool')}
            </span>
            {row.actions.claim ? (
              <button
                type="button"
                className={buttonClass('outline', 'sm', 'px-2 py-0.5 text-11')}
                disabled={claim.isPending}
                onClick={() => claim.mutate(row.id, { onSuccess: () => toast(t('ownership.claimed')), onError: (error) => toast(error.message, 'error') })}
              >
                {t('ownership.claim')}
              </button>
            ) : null}
          </span>
        ),
    },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) => (
        <span className="flex flex-col items-start gap-0.5">
          <Badge tone={row.status === 'quoted' ? 'green' : row.stale ? 'red' : 'orange'}>{t(`air.status.${row.status}`)}</Badge>
          {row.quoted_by ? <span className="text-12 text-app-muted">{row.quoted_by.name}</span> : null}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('air.title')} subtitle={t('air.subtitle')} />

      <div className="flex flex-wrap gap-x-5 gap-y-2.5">
        <Chips label={t('common.status')} value={filters.state} onChange={(state) => set({ state, stale: state === 'open' ? filters.stale : false })} options={STATES.map((value) => ({ value, label: t(`air.states.${value}`) }))} />
        {filters.state === 'open' ? (
          <Chips label={t('air.waitingFilter')} value={filters.stale ? 'stale' : 'any'} onChange={(value) => set({ stale: value === 'stale' })} options={[{ value: 'any', label: t('air.anyAge') }, { value: 'stale', label: t('air.over24h') }]} />
        ) : null}
        <Chips label={t('ownership.owner')} value={filters.owner} onChange={(owner) => set({ owner })} options={OWNERS.map((value) => ({ value, label: t(`ownership.${value}`) }))} />
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-app-line p-3.5">
          <CardTitle bn="টিকেট ইনকোয়্যারি" en="Ticket enquiries" aside={<span className="text-12 text-app-muted">{t('air.queueNote')}</span>} />
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('air.search')} aria-label={t('air.search')} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('air.empty')} note={t('air.emptyNote')} />
        ) : (
          <DataTable label={t('air.title')} testId="air-inquiries-table" columns={columns} rows={list.data.data} rowKey={(row) => row.id} rowLabel={(row) => row.name} actions={actionsFor} onRowClick={setViewing} />
        )}
        {list.data && list.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: number(filters.page), last: number(list.data.meta.last_page) })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= list.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>
      {viewing ? <InquiryDialog row={viewing} onClose={() => setViewing(null)} /> : null}
    </>
  )
}

function InquiryDialog({ row, onClose }: { row: AirInquiry; onClose: () => void }) {
  const { t } = useTranslation()
  const { date, dateTime, number, digits } = useFormat()
  const facts: [string, string][] = [
    [t('air.passenger'), row.name],
    [t('newBooking.phone'), digits(row.phone.replace(/^88/, ''))],
    [t('newBooking.email'), row.email ?? '—'],
    [t('air.route'), `${row.from ?? '?'} → ${row.to ?? '?'}`],
    [t('air.departOn'), row.depart_on ? date(row.depart_on) : '—'],
    [t('air.returnOn'), row.return_on ? date(row.return_on) : t('air.oneWay')],
    [t('air.passengersLabel'), number(row.passengers ?? 1)],
    [t('air.cabinLabel'), t(`air.cabin.${row.cabin_class ?? 'economy'}`)],
    [t('air.received'), dateTime(row.created_at)],
    [t('air.language'), row.locale === 'en' ? 'English' : 'বাংলা'],
  ]

  return (
    <Dialog open onClose={onClose} title={t('air.enquiryFrom', { name: row.name })}>
      <dl className="m-0 grid-auto-fit-half-160 grid gap-x-4 gap-y-2.5 text-14">
        {facts.map(([label, value]) => (
          <div key={label} className="flex flex-col">
            <dt className="text-12 text-app-muted">{label}</dt>
            <dd className="m-0 font-medium">{value}</dd>
          </div>
        ))}
      </dl>
      {row.quoted_at ? <p className="m-0 text-13 text-green">{t('air.quotedBy', { name: row.quoted_by?.name ?? '—', when: dateTime(row.quoted_at) })}</p> : null}
    </Dialog>
  )
}
