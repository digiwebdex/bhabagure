import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { INVOICE_STATES, useInvoices, type InvoiceFilters, type InvoiceRow, type InvoiceState } from './api'
import { InvoiceEditor } from './InvoiceEditor'
import { InvoicePayment } from './InvoicePayment'

/**
 * Invoices (docs/phase-9-accounts.md §5): everything issued, by state, with the money owed. A booking's invoice is
 * listed but belongs to its booking; the rest are written here.
 */
export function InvoicesPage() {
  const { t } = useTranslation()
  const { bdt, date, digits } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const state = (INVOICE_STATES as readonly string[]).includes(params.get('state') ?? '') ? (params.get('state') as InvoiceState) : 'all'
  const filters: InvoiceFilters = {
    state,
    customer_id: params.get('customer_id') ?? '',
    from: params.get('from') ?? '',
    to: params.get('to') ?? '',
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const list = useInvoices(filters)
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [paying, setPaying] = useState<InvoiceRow | null>(null)

  const set = (patch: Partial<InvoiceFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries(next)) if (value && !(key === 'state' && value === 'all') && !(key === 'page' && value === 1)) query.set(key, String(value))
    setParams(query, { replace: true })
  }

  const share = async (row: InvoiceRow, kind: 'copy' | 'pdf') => {
    if (kind === 'copy') {
      const url = `${window.location.origin.replace('admin.', '')}/i/${row.number}`
      try {
        await navigator.clipboard.writeText(url)
        toast(t('invoices.linkCopied'))
      } catch {
        toast(url)
      }
      return
    }
    try {
      const blob = await fetchDocument(`admin/deals/${row.id}/pdf`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('invoices.pdfFailed'), 'error')
    }
  }

  const actionsFor = (row: InvoiceRow): RowAction[] => [
    ...(row.booking_id
      ? [{ key: 'booking', icon: '◉', label: t('invoices.openBooking'), tone: 'muted' as const, to: `/bookings/${row.booking_id}` }]
      : [{ key: 'open', icon: '◉', label: t('invoices.open'), tone: 'muted' as const, onSelect: () => setEditing(row.id) }]),
    ...(can('transactions.create_manual')
      ? [
          {
            key: 'pay',
            icon: '৳',
            label: t('invoices.recordPayment'),
            tone: 'green' as const,
            onSelect: () => setPaying(row),
            disabledReason: row.actions.pay ? undefined : row.booking_id ? t('invoices.payOnBooking') : row.status === 'draft' ? t('invoices.draftOnly') : t('invoices.nothingDue'),
          },
        ]
      : []),
    { key: 'pdf', icon: '⎘', label: t('invoices.pdf'), tone: 'blue', onSelect: () => void share(row, 'pdf'), disabledReason: row.actions.share ? undefined : t('invoices.draftOnly') },
    { key: 'share', icon: '↗', label: t('invoices.share'), tone: 'purple', onSelect: () => void share(row, 'copy'), disabledReason: row.actions.share ? undefined : t('invoices.draftOnly') },
    // Send reminder: the staff member's own SMS or email app, with the number and the amount already written.
    ...contactActions(t, {
      phone: row.customer?.phone,
      email: row.customer?.email,
      channels: ['sms', 'email'],
      subject: t('invoices.reminderSubject', { number: row.number ?? '' }),
      message: t('invoices.reminderMessage', { number: row.number ?? '', amount: bdt(row.due) }),
    }).map((action) => ({ ...action, disabledReason: row.due > 0 ? action.disabledReason : t('invoices.nothingDue') })),
  ]

  const columns: Column<InvoiceRow>[] = [
    {
      key: 'number',
      header: t('invoices.columns.number'),
      cell: (row) => (
        <span className="flex flex-col gap-0.5">
          <span className="font-display font-medium">{row.number ?? t('invoices.draft')}</span>
          <span className="text-12 text-app-muted">{row.title}</span>
        </span>
      ),
    },
    {
      key: 'customer',
      header: t('invoices.columns.customer'),
      cell: (row) =>
        row.customer ? (
          <span className="flex flex-col gap-0.5">
            <Link to={`/customers/${row.customer.id}`} className="font-medium" onClick={(event) => event.stopPropagation()}>
              {row.customer.name}
            </Link>
            <span className="font-display text-12 text-app-muted">{digits(row.customer.phone.replace(/^88/, ''))}</span>
          </span>
        ) : (
          <span>{row.billed_name ?? '—'}</span>
        ),
    },
    { key: 'date', header: t('invoices.columns.date'), cell: (row) => <span className="whitespace-nowrap">{row.issued_on ? date(row.issued_on) : '—'}</span> },
    { key: 'total', header: t('invoices.columns.total'), align: 'right', cell: (row) => <span className="font-display">{bdt(row.total)}</span> },
    {
      key: 'due',
      header: t('invoices.columns.due'),
      align: 'right',
      cell: (row) => (
        <span className="flex flex-col items-end">
          <span className={`font-display ${row.due > 0 ? 'font-bold' : 'text-app-muted'}`}>{bdt(row.due)}</span>
          {row.overdue ? <span className="text-11 text-red">{t('invoices.daysOverdue', { count: row.days_overdue })}</span> : null}
        </span>
      ),
    },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) => (
        <Badge tone={row.status === 'void' ? 'slate' : row.status === 'draft' ? 'blue' : row.payment_status === 'paid' ? 'green' : row.overdue ? 'red' : 'orange'}>
          {row.status === 'issued' ? t(`invoices.states.${row.payment_status}`) : t(`invoices.states.${row.status}`)}
        </Badge>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('invoices.title')}
        subtitle={t('invoices.subtitle')}
        actions={
          can('invoices.manage') ? (
            <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setEditing('new')}>
              {t('invoices.create')}
            </button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap gap-2" role="tablist" aria-label={t('invoices.title')}>
        {(['all', 'unpaid', 'partial', 'paid', 'overdue', 'draft'] as const).map((tab) => (
          <button
            key={tab}
            type="button"
            role="tab"
            aria-selected={state === tab}
            onClick={() => set({ state: tab })}
            className={`cursor-pointer rounded-pill px-4 py-1.75 text-13 font-semibold ${state === tab ? 'bg-blue text-white' : 'border border-app-line bg-app-surface text-app-muted'}`}
          >
            {t(`invoices.tabs.${tab}`)}
            {list.data ? <span className="ml-1.5 font-display text-12 opacity-80">{list.data.meta.tabs[tab]}</span> : null}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('invoices.from')}
          <input type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => set({ from: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('invoices.to')}
          <input type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => set({ to: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex min-w-60 flex-1 flex-col gap-1 text-12 text-app-muted">
          {t('invoices.search')}
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('invoices.searchPlaceholder')} className={controlClass()} />
        </label>
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-app-line p-3.5">
          <CardTitle title={t('invoices.cardTitle')} />
          {list.data ? (
            <span className="flex gap-4 text-13">
              <span className="text-app-muted">
                {t('invoices.invoiced')} <strong className="font-display text-app-text">{bdt(list.data.meta.totals.invoiced)}</strong>
              </span>
              <span className="text-app-muted">
                {t('invoices.outstanding')} <strong className="font-display text-app-text">{bdt(list.data.meta.totals.due)}</strong>
              </span>
            </span>
          ) : null}
        </div>

        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('invoices.empty')} note={t('invoices.emptyNote')} />
        ) : (
          <DataTable
            label={t('invoices.title')}
            testId="invoices-table"
            columns={columns}
            rows={list.data.data}
            rowKey={(row) => row.id}
            rowLabel={(row) => row.number ?? t('invoices.draft')}
            actions={actionsFor}
            onRowClick={(row) => (row.booking_id ? undefined : setEditing(row.id))}
          />
        )}

        {list.data && list.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: filters.page, last: list.data.meta.last_page })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= list.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>

      {editing !== null ? <InvoiceEditor id={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
      {paying !== null ? <InvoicePayment invoice={paying} onClose={() => setPaying(null)} /> : null}
    </>
  )
}
