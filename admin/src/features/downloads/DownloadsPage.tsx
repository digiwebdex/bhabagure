import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { useDownloads, type DownloadFilters, type DownloadRow } from './api'

const KINDS = ['all', 'package', 'visa'] as const
const SITE_URL = (import.meta.env.VITE_SITE_URL ?? '').replace(/\/$/, '')
const DATE = /^\d{4}-\d{2}-\d{2}$/

/** Filters live in the URL, so a filtered list can be shared or reopened. */
function readFilters(params: URLSearchParams): DownloadFilters {
  const kind = params.get('kind')
  const date = (value: string | null) => (value && DATE.test(value) ? value : '')
  return {
    kind: KINDS.includes(kind as (typeof KINDS)[number]) ? (kind as DownloadFilters['kind']) : 'all',
    followUp: params.get('follow_up') === '1',
    search: params.get('search') ?? '',
    from: date(params.get('from')),
    to: date(params.get('to')),
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/**
 * The Downloads screen (docs/phase-8-visa-quotes-pricing-downloads.md §4.E): every package brochure and visa-requirements
 * PDF a signed-in customer downloaded from the website — who, what, when, in which hotel category and for how many
 * travellers — and whether a booking or quotation is already in progress, so the rest can be followed up.
 */
export function DownloadsPage() {
  const { t } = useTranslation()
  const { number, dateTime, digits } = useFormat()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useDownloads(filters)
  const totals = list.data?.meta.totals

  const set = (patch: Partial<DownloadFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.kind !== 'all') query.set('kind', next.kind)
    if (next.followUp) query.set('follow_up', '1')
    if (next.search) query.set('search', next.search)
    if (next.from) query.set('from', next.from)
    if (next.to) query.set('to', next.to)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (row: DownloadRow): RowAction[] => [
    { key: 'view', icon: '◉', label: t('downloads.openCustomer'), tone: 'muted', to: `/customers/${row.customer.id}` },
    ...contactActions(t, {
      phone: row.customer.phone,
      email: row.customer.email,
      channels: ['whatsapp', 'email'],
      subject: row.title,
      message: t('downloads.followUpMessage', { name: row.customer.name, title: row.title }),
    }),
  ]

  const columns: Column<DownloadRow>[] = [
    {
      key: 'customer',
      header: t('downloads.columns.customer'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <Link to={`/customers/${row.customer.id}`} className="font-medium" onClick={(event) => event.stopPropagation()}>
            {row.customer.name}
          </Link>
          <span className="font-display text-12 text-app-muted">{digits(row.customer.phone.replace(/^88/, ''))}</span>
          <span className="text-12 text-app-muted">
            {t(`downloads.stage.${row.customer.stage}`)}
            {row.customer.owner ? ` · ${row.customer.owner}` : ` · ${t('ownership.pool')}`}
            {row.customer.downloads > 1 ? ` · ${t('downloads.times', { count: row.customer.downloads, n: number(row.customer.downloads) })}` : ''}
          </span>
        </div>
      ),
    },
    {
      key: 'what',
      header: t('downloads.columns.what'),
      cell: (row) => (
        <div className="flex max-w-80 flex-col gap-0.5">
          <span className="flex items-center gap-1.5">
            <Badge tone={row.kind === 'package' ? 'blue' : 'orange'}>{t(`downloads.kinds.${row.kind}`)}</Badge>
            {row.slug && SITE_URL ? (
              <a href={`${SITE_URL}/${row.kind === 'package' ? 'packages' : 'visa'}/${row.slug}`} target="_blank" rel="noopener noreferrer" className="truncate font-medium" onClick={(event) => event.stopPropagation()}>
                {row.title}
              </a>
            ) : (
              <span className="truncate font-medium">{row.title}</span>
            )}
          </span>
          {row.kind === 'package' ? (
            <span className="text-12 text-app-muted" data-testid="download-choice">
              {[row.hotel_category ? t(`grid.categories.${row.hotel_category}`) : null, row.pax ? t('grid.tier', { count: row.pax, n: number(row.pax) }) : null].filter(Boolean).join(' · ')}
            </span>
          ) : null}
        </div>
      ),
    },
    { key: 'when', header: t('downloads.columns.when'), cell: (row) => <span className="text-13 whitespace-nowrap">{dateTime(row.created_at)}</span> },
    {
      key: 'progress',
      header: t('downloads.columns.progress'),
      cell: (row) =>
        row.in_progress.booking || row.in_progress.quotation ? (
          <span className="flex flex-wrap gap-1">
            {row.in_progress.booking ? <Badge tone="green">{t('downloads.hasBooking')}</Badge> : null}
            {row.in_progress.quotation ? <Badge tone="green">{t('downloads.hasQuotation')}</Badge> : null}
          </span>
        ) : (
          <Badge tone="red">{t('downloads.needsFollowUp')}</Badge>
        ),
    },
  ]

  return (
    <>
      <PageHeader title={t('downloads.title')} subtitle={t('downloads.subtitle')} />

      <div className="grid-auto-fit-200 grid gap-3.5" data-testid="download-totals">
        <Total label={t('downloads.totals.downloads')} value={totals ? number(totals.downloads) : '—'} />
        <Total label={t('downloads.totals.customers')} value={totals ? number(totals.customers) : '—'} />
        <Total label={t('downloads.totals.packages')} value={totals ? number(totals.packages) : '—'} />
        <Total label={t('downloads.totals.visas')} value={totals ? number(totals.visas) : '—'} />
      </div>

      <div className="flex flex-wrap items-end gap-x-5 gap-y-2.5">
        <Chips label={t('downloads.kind')} value={filters.kind} onChange={(kind) => set({ kind })} options={KINDS.map((value) => ({ value, label: t(`downloads.kinds.${value}`) }))} />
        <Chips
          label={t('downloads.followUp')}
          value={filters.followUp ? 'follow' : 'everyone'}
          onChange={(value) => set({ followUp: value === 'follow' })}
          options={[
            { value: 'everyone', label: t('downloads.everyone') },
            { value: 'follow', label: t('downloads.needsFollowUp') },
          ]}
        />
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('downloads.from')}
          <input type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => set({ from: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('downloads.to')}
          <input type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => set({ to: event.target.value })} className={controlClass()} />
        </label>
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-app-line p-3.5">
          <CardTitle title="Website downloads" aside={<span className="text-12 text-app-muted">{t('downloads.note')}</span>} />
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('downloads.search')} aria-label={t('downloads.search')} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('downloads.empty')} note={t('downloads.emptyNote')} />
        ) : (
          <DataTable label={t('downloads.title')} testId="downloads-table" columns={columns} rows={list.data.data} rowKey={(row) => row.id} rowLabel={(row) => row.customer.name} actions={actionsFor} />
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

function Total({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1.25 rounded-16 border border-app-line bg-app-surface px-5 py-4.5">
      <span className="text-13 text-app-muted">{label}</span>
      <span className="font-display text-25 font-extrabold">{value}</span>
    </div>
  )
}
