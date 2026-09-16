import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { ticketTone, useSupportTickets, type SupportFilters, type SupportTicket, type TicketStatus } from './api'

const STATUSES = ['open', 'answered', 'closed', 'all'] as const

/**
 * Support — requests customers open in the portal (docs/phase-6-customer-portal.md §3.5). One shared queue: open tickets
 * oldest first, flagged after the 24 hours the portal promises. A reply goes to the customer by WhatsApp and email.
 */
export function SupportPage() {
  const { t } = useTranslation()
  const { number, relativeAge, digits } = useFormat()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const filters: SupportFilters = {
    status: STATUSES.includes(params.get('status') as TicketStatus) ? (params.get('status') as SupportFilters['status']) : 'open',
    overdue: params.get('overdue') === '1',
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const list = useSupportTickets(filters)

  const set = (patch: Partial<SupportFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams({ status: next.status })
    if (next.overdue) query.set('overdue', '1')
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (row: SupportTicket): RowAction[] => [
    { key: 'open', icon: '◉', label: t('support.openTicket'), tone: 'muted', to: `/support/${row.id}` },
    ...contactActions(t, { phone: row.customer.phone, email: null, channels: ['whatsapp'], message: t('support.contactMessage', { name: row.customer.name, number: row.number }) }),
    { key: 'customer', icon: '→', label: t('support.openCustomer'), tone: 'blue', to: `/customers/${row.customer.id}` },
  ]

  const columns: Column<SupportTicket>[] = [
    {
      key: 'subject',
      header: t('support.subject'),
      cell: (row) => (
        <div className="flex max-w-80 flex-col gap-0.5">
          <span className="truncate font-medium">{row.subject}</span>
          <span className="font-display text-12 text-app-muted">
            {row.number}
            {row.booking ? ` · ${row.booking.reference}` : ''} · {t('support.messages', { count: row.messages_count, n: number(row.messages_count) })}
          </span>
        </div>
      ),
    },
    {
      key: 'customer',
      header: t('support.customer'),
      cell: (row) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.customer.name}</span>
          <span className="font-display text-12 text-app-muted">{digits(row.customer.phone.replace(/^88/, ''))}</span>
        </div>
      ),
    },
    { key: 'owner', header: t('support.tripOwner'), cell: (row) => <span className="text-13">{row.booking?.assigned_staff?.name ?? '—'}</span> },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) => (
        <span className="flex flex-col items-start gap-0.5">
          <Badge tone={ticketTone(row)}>{row.overdue ? t('support.overdue') : t(`support.status.${row.status}`)}</Badge>
          {row.status === 'open' ? (
            <span className="text-12 text-app-muted">{t('support.waiting', { age: relativeAge(Math.floor((Date.now() - Date.parse(row.last_customer_message_at)) / 60_000)) })}</span>
          ) : null}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('support.title')} subtitle={t('support.subtitle')} />
      <div className="flex flex-wrap gap-x-5 gap-y-2.5">
        <Chips label={t('common.status')} value={filters.status} onChange={(status) => set({ status, overdue: status === 'open' ? filters.overdue : false })} options={STATUSES.map((value) => ({ value, label: t(`support.states.${value}`) }))} />
        {filters.status === 'open' ? (
          <Chips label={t('support.waitingFilter')} value={filters.overdue ? 'overdue' : 'any'} onChange={(value) => set({ overdue: value === 'overdue' })} options={[{ value: 'any', label: t('support.anyAge') }, { value: 'overdue', label: t('support.over24h') }]} />
        ) : null}
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-app-line p-3.5">
          <CardTitle title="Support tickets" aside={<span className="text-12 text-app-muted">{t('support.queueNote')}</span>} />
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('support.search')} aria-label={t('support.search')} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('support.empty')} note={t('support.emptyNote')} />
        ) : (
          <DataTable label={t('support.title')} testId="support-tickets-table" columns={columns} rows={list.data.data} rowKey={(row) => row.id} rowLabel={(row) => row.number} actions={actionsFor} onRowClick={(row) => navigate(`/support/${row.id}`)} />
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
    </>
  )
}
