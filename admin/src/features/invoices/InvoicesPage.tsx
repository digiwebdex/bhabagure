import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Badge, Card, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { api, fetchDocument } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { INVOICE_STATES, printPath, useInvoiceAction, useInvoices, type InvoiceFilters, type InvoiceRow, type InvoiceState, type PrintSize } from './api'
import { InvoiceDetails } from './InvoiceDetails'
import { InvoicePayment } from './InvoicePayment'
import { InvoiceShare } from './InvoiceShare'

/** The tabs the money is read by, in the order the old system had them. Drafts are ours: it had none. */
const TABS = ['all', 'unpaid', 'partial', 'paid', 'overdue', 'draft'] as const

type Dialog = { kind: 'payment' | 'details' | 'share' | 'remind'; row: InvoiceRow } | null

/**
 * Invoices (docs/phase-9-accounts.md §5): everything issued, by state, with what is still owed. A booking's invoice
 * is listed but belongs to its booking; the rest are written here.
 */
export function InvoicesPage() {
  const { t } = useTranslation()
  const { bdt, date, digits } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const { confirm, element: confirmDialog } = useConfirm()
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
  const [open, setOpen] = useState<Dialog>(null)
  const remove = useInvoiceAction((id: number) => api.delete<Data<{ deleted: boolean }>>(`admin/invoices/${id}`))

  const set = (patch: Partial<InvoiceFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries(next)) if (value && !(key === 'state' && value === 'all') && !(key === 'page' && value === 1)) query.set(key, String(value))
    setParams(query, { replace: true })
  }

  const print = async (row: InvoiceRow, size: PrintSize) => {
    try {
      const blob = await fetchDocument(printPath(row.id, size))
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('invoices.pdfFailed'), 'error')
    }
  }

  const discard = async (row: InvoiceRow) => {
    if (!(await confirm(t('invoices.deleteConfirm')))) return
    remove.mutate(row.id, { onSuccess: () => toast(t('invoices.deleted')) })
  }

  return (
    <>
      <PageHeader
        title={t('invoices.title')}
        subtitle={t('invoices.subtitle')}
        actions={
          can('invoices.manage') ? (
            <Link to="/invoices/new" className={buttonClass('primary', 'sm')}>
              {t('invoices.create')}
            </Link>
          ) : undefined
        }
      />

      {/* One bar, the state picked out in white — the way the old system reads. */}
      <div className="flex justify-center">
        <div className="flex flex-wrap justify-center gap-1 rounded-pill bg-linear-90/srgb from-blue to-blue-abyss p-1" role="tablist" aria-label={t('invoices.title')}>
          {TABS.map((tab) => (
            <button
              key={tab}
              type="button"
              role="tab"
              aria-selected={state === tab}
              onClick={() => set({ state: tab })}
              className={`cursor-pointer rounded-pill px-5 py-1.75 text-13 font-semibold transition-colors duration-150 ${state === tab ? 'bg-app-surface text-app-text' : 'bg-transparent text-white/85 hover:text-white'}`}
            >
              {t(`invoices.tabs.${tab}`)}
              {list.data ? <span className="ml-1.5 font-display text-12 opacity-75">{list.data.meta.tabs[tab]}</span> : null}
            </button>
          ))}
        </div>
      </div>

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <CustomerFilter value={filters.customer_id} onChange={(customer_id) => set({ customer_id })} />
          <label className="flex flex-col gap-1 text-12 text-app-muted">
            {t('invoices.from')}
            <input type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => set({ from: event.target.value })} className={controlClass()} />
          </label>
          <label className="flex flex-col gap-1 text-12 text-app-muted">
            {t('invoices.to')}
            <input type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => set({ to: event.target.value })} className={controlClass()} />
          </label>
          <label className="flex flex-col gap-1 text-12 text-app-muted">
            {t('invoices.invoiceId')}
            <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('invoices.searchPlaceholder')} className={controlClass()} />
          </label>
        </div>
      </Card>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-end gap-4 border-b border-app-line px-4 py-3 text-13">
          {list.data ? (
            <>
              <span className="text-app-muted">
                {t('invoices.invoiced')} <strong className="font-display text-app-text">{bdt(list.data.meta.totals.invoiced)}</strong>
              </span>
              <span className="text-app-muted">
                {t('invoices.outstanding')} <strong className="font-display text-app-text">{bdt(list.data.meta.totals.due)}</strong>
              </span>
            </>
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
          <div className="overflow-x-auto">
            <table className="w-full min-w-max border-collapse text-13" data-testid="invoices-table" aria-label={t('invoices.title')}>
              <thead>
                <tr className="border-b border-app-line bg-app-surface-2 text-12 text-app-muted">
                  <th className="p-3 text-left font-semibold">{t('invoices.columns.number')}</th>
                  <th className="p-3 text-left font-semibold">{t('invoices.columns.customer')}</th>
                  <th className="p-3 text-left font-semibold">{t('invoices.columns.date')}</th>
                  <th className="p-3 text-right font-semibold">{t('invoices.columns.total')}</th>
                  <th className="p-3 text-right font-semibold">{t('invoices.columns.due')}</th>
                  <th className="p-3 text-left font-semibold">{t('common.status')}</th>
                  <th className="p-3 text-right font-semibold">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {list.data.data.map((row) => (
                  <tr key={row.id} className="border-b border-app-line last:border-0 hover:bg-app-surface-2">
                    <td className="p-3 align-top">
                      <span className="flex flex-col gap-0.5">
                        <button type="button" className="cursor-pointer self-start border-0 bg-transparent p-0 font-display text-13 font-bold text-blue" onClick={() => setOpen({ kind: 'details', row })}>
                          {row.number ? `#${row.number}` : t('invoices.draft')}
                        </button>
                        {row.created_by ? <span className="text-11 text-app-muted">{t('invoices.createdBy', { name: row.created_by })}</span> : null}
                        {row.updated_by ? <span className="text-11 text-green-deep">{t('invoices.updatedBy', { name: row.updated_by })}</span> : null}
                      </span>
                    </td>
                    <td className="p-3 align-top">
                      {row.customer ? (
                        <span className="flex items-center gap-2">
                          <Avatar name={row.customer.name} />
                          <span className="flex flex-col">
                            <Link to={`/customers/${row.customer.id}`} className="font-medium">
                              {row.customer.name}
                            </Link>
                            <span className="font-display text-11 text-app-muted">{digits(row.customer.phone.replace(/^88/, ''))}</span>
                          </span>
                        </span>
                      ) : (
                        <span>{row.billed_name ?? '—'}</span>
                      )}
                    </td>
                    <td className="p-3 align-top whitespace-nowrap">{row.issued_on ? date(row.issued_on) : '—'}</td>
                    <td className="p-3 text-right align-top font-display font-bold text-blue">{bdt(row.total)}</td>
                    <td className="p-3 text-right align-top">
                      <span className="flex flex-col items-end">
                        <span className={`font-display ${row.due > 0 ? 'font-bold' : 'text-app-muted'}`}>{bdt(row.due)}</span>
                        {row.overdue ? <span className="text-11 text-red">{t('invoices.daysOverdue', { count: row.days_overdue })}</span> : null}
                      </span>
                    </td>
                    <td className="p-3 align-top">
                      <Badge tone={row.status === 'void' ? 'slate' : row.status === 'draft' ? 'blue' : row.payment_status === 'paid' ? 'green' : row.overdue ? 'red' : 'orange'}>
                        {row.overdue ? t('invoices.states.overdue') : row.status === 'issued' ? t(`invoices.states.${row.payment_status}`) : t(`invoices.states.${row.status}`)}
                      </Badge>
                    </td>
                    <td className="p-3 align-top">
                      <span className="flex items-start justify-end gap-2">
                        <span className="flex flex-col items-end gap-1">
                          {row.actions.pay && can('transactions.create_manual') ? (
                            <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setOpen({ kind: 'payment', row })}>
                              {t('invoices.payment')}
                            </button>
                          ) : null}
                          {row.actions.share ? (
                            <button
                              type="button"
                              className="cursor-pointer border-0 bg-transparent p-0 text-12 font-semibold text-blue"
                              onClick={() => setOpen({ kind: row.due > 0 ? 'remind' : 'share', row })}
                            >
                              {row.due > 0 ? t('invoices.sendReminder') : t('invoices.share')}
                            </button>
                          ) : null}
                        </span>
                        <RowMenu
                          row={row}
                          onPrint={(size) => void print(row, size)}
                          onDetails={() => setOpen({ kind: 'details', row })}
                          onEdit={() => navigate(`/invoices/${row.id}/edit`)}
                          onShare={() => setOpen({ kind: 'share', row })}
                          onRemind={() => setOpen({ kind: 'remind', row })}
                          onDelete={() => void discard(row)}
                          canManage={can('invoices.manage')}
                          canSend={can('notifications.send')}
                        />
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
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

      {open?.kind === 'payment' ? <InvoicePayment invoice={open.row} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'details' ? <InvoiceDetails id={open.row.id} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'share' || open?.kind === 'remind' ? <InvoiceShare invoice={open.row} purpose={open.kind === 'share' ? 'share' : 'remind'} onClose={() => setOpen(null)} /> : null}
      {confirmDialog}
    </>
  )
}

/** A customer's initials where the old system had a photo: we keep no pictures of customers. */
function Avatar({ name }: { name: string }) {
  const initials = name
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0] ?? '')
    .join('')
    .toUpperCase()

  return <span aria-hidden="true" className="flex size-7 shrink-0 items-center justify-center rounded-pill bg-blue-tint text-11 font-bold text-blue-deep">{initials}</span>
}

/** Find a customer by name or number and keep the list to theirs. */
function CustomerFilter({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { t } = useTranslation()
  const [lookup, setLookup] = useState('')
  const hits = useQuery({
    queryKey: ['search', lookup.trim()],
    queryFn: ({ signal }) =>
      api.get<Data<{ customers?: { id: number; name: string; phone: string }[] }>>(`admin/search?q=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data.customers ?? []),
    enabled: lookup.trim().length >= 2,
  })
  const chosen = hits.data?.find((hit) => String(hit.id) === value)

  return (
    <label className="flex flex-col gap-1 text-12 text-app-muted">
      {t('invoices.columns.customer')}
      {value ? (
        <span className="flex items-center justify-between gap-2 rounded-10 border border-app-line px-3 py-2 text-13 text-app-text">
          {chosen?.name ?? t('invoices.thisCustomer')}
          <button type="button" className="cursor-pointer border-0 bg-transparent p-0 text-12 font-semibold text-blue" onClick={() => onChange('')}>
            {t('invoices.clearCustomer')}
          </button>
        </span>
      ) : (
        <input type="search" value={lookup} onChange={(event) => setLookup(event.target.value)} placeholder={t('invoices.findCustomerHint')} className={controlClass()} />
      )}
      {!value && hits.data && hits.data.length > 0 ? (
        <span className="flex flex-wrap gap-1.5 pt-1">
          {hits.data.slice(0, 4).map((hit) => (
            <button key={hit.id} type="button" className={buttonClass('outline', 'sm')} onClick={() => { onChange(String(hit.id)); setLookup('') }}>
              {hit.name}
            </button>
          ))}
        </span>
      ) : null}
    </label>
  )
}

/** Everything else the row can do, behind one button, as the old system had it. */
function RowMenu({
  row,
  onPrint,
  onDetails,
  onEdit,
  onShare,
  onRemind,
  onDelete,
  canManage,
  canSend,
}: {
  row: InvoiceRow
  onPrint: (size: PrintSize) => void
  onDetails: () => void
  onEdit: () => void
  onShare: () => void
  onRemind: () => void
  onDelete: () => void
  canManage: boolean
  canSend: boolean
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  // Clicking anywhere else, or pressing Escape, closes it — a menu that traps the page is worse than no menu.
  useEffect(() => {
    if (!open) return
    const away = (event: MouseEvent) => {
      if (!box.current?.contains(event.target as Node)) setOpen(false)
    }
    const key = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', key)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', key)
    }
  }, [open])

  const items: { key: string; label: string; onSelect: () => void; danger?: boolean }[] = [
    ...(row.actions.share
      ? ([
          { key: 'a4', label: t('invoices.printSizes.a4'), onSelect: () => onPrint('a4') },
          { key: 'a5', label: t('invoices.printSizes.a5'), onSelect: () => onPrint('a5') },
          { key: 'slip', label: t('invoices.printSizes.slip'), onSelect: () => onPrint('slip') },
          { key: 'delivery', label: t('invoices.printSizes.delivery'), onSelect: () => onPrint('delivery') },
        ] as const)
      : []),
    { key: 'details', label: t('invoices.details'), onSelect: onDetails },
    ...(row.actions.edit && canManage ? [{ key: 'edit', label: t('table.edit'), onSelect: onEdit }] : []),
    ...(row.actions.share && canSend ? [{ key: 'mail', label: t('invoices.sendReminder'), onSelect: onRemind }] : []),
    ...(row.actions.share ? [{ key: 'share', label: t('invoices.share'), onSelect: onShare }] : []),
    ...(row.actions.edit && canManage ? [{ key: 'delete', label: t('table.delete'), onSelect: onDelete, danger: true }] : []),
  ]

  return (
    <div className="relative" ref={box}>
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={t('table.actionsFor', { name: row.number ?? t('invoices.draft') })}
        className="flex size-7 cursor-pointer items-center justify-center rounded-pill border border-blue bg-transparent text-12 text-blue hover:bg-blue-tint"
        onClick={() => setOpen((was) => !was)}
      >
        ⌄
      </button>
      {open ? (
        <div role="menu" className="absolute top-8 right-0 z-30 flex min-w-44 flex-col rounded-10 border border-app-line bg-app-surface py-1 shadow-modal">
          {items.map((item) => (
            <button
              key={item.key}
              type="button"
              role="menuitem"
              className={`cursor-pointer border-0 bg-transparent px-3.5 py-1.75 text-left text-13 hover:bg-app-surface-2 ${item.danger ? 'text-red' : 'text-app-text'}`}
              onClick={() => {
                setOpen(false)
                item.onSelect()
              }}
            >
              {item.label}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
