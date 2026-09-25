import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Badge, Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { fileSize, useVoucherFile, useVouchers, VOUCHER_VIEWS, type Voucher, type VoucherFilters, type VoucherView } from './api'
import { ArchiveVoucherDialog, UploadVoucherDialog } from './VoucherDialogs'

/** Filters live in the URL, so a filtered list can be shared or reopened. */
function readFilters(params: URLSearchParams): VoucherFilters {
  const view = params.get('view')
  return {
    view: VOUCHER_VIEWS.includes(view as VoucherView) ? (view as VoucherView) : 'upcoming',
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
}

/**
 * Sales → Vouchers (docs/booking-vouchers.md): suppliers' confirmation vouchers and contracts. Upcoming ones first (soonest
 * service date on top, then those without a date), then past ones, archived ones apart. A click opens the file; the
 * download saves it under its own name. Reading needs vouchers.view; uploading and archiving vouchers.manage.
 */
export function VouchersPage() {
  const { t } = useTranslation()
  const { number, date, dateTime } = useFormat()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const filters = readFilters(params)
  const list = useVouchers(filters)
  const file = useVoucherFile()
  const manage = can('vouchers.manage')
  const [uploading, setUploading] = useState(false)
  const [archiving, setArchiving] = useState<Voucher | null>(null)
  const counts = list.data?.meta.counts

  const set = (patch: Partial<VoucherFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.view !== 'upcoming') query.set('view', next.view)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (voucher: Voucher): RowAction[] => [
    { key: 'open', icon: '👁', label: t('vouchers.open'), tone: 'blue', onSelect: () => void file.open(voucher) },
    { key: 'download', icon: '⬇', label: t('vouchers.download'), tone: 'green', onSelect: () => void file.download(voucher) },
    ...(voucher.archived_at === null
      ? [{ key: 'archive', icon: '✕', label: t('vouchers.archive'), tone: 'red' as const, disabledReason: manage ? undefined : t('vouchers.noManagePermission'), onSelect: () => setArchiving(voucher) }]
      : []),
  ]

  const columns: Column<Voucher>[] = [
    {
      key: 'title',
      header: t('vouchers.columns.title'),
      cell: (voucher) => (
        <div className="flex flex-col items-start gap-0.5">
          <button type="button" onClick={() => void file.open(voucher)} className="cursor-pointer text-left font-semibold text-blue hover:underline">
            {voucher.title}
          </button>
          <span className="text-12 text-app-muted">
            <Badge tone={voucher.mime === 'application/pdf' ? 'red' : 'blue'}>{voucher.mime === 'application/pdf' ? 'PDF' : 'JPG'}</Badge> {voucher.original_name} · {fileSize(voucher.bytes)}
          </span>
        </div>
      ),
    },
    {
      key: 'booking',
      header: t('vouchers.columns.booking'),
      cell: (voucher) =>
        voucher.booking ? (
          <Link to={`/bookings/${voucher.booking.id}`} className="font-display font-semibold whitespace-nowrap">
            {voucher.booking.reference}
          </Link>
        ) : (
          <span className="text-app-muted">—</span>
        ),
    },
    {
      key: 'date',
      header: t('vouchers.columns.date'),
      cell: (voucher) => <span className="whitespace-nowrap">{voucher.service_date ? date(voucher.service_date) : <span className="text-app-muted">{t('vouchers.noDate')}</span>}</span>,
    },
    {
      key: 'uploaded',
      header: filters.view === 'archived' ? t('vouchers.columns.archived') : t('vouchers.columns.uploaded'),
      cell: (voucher) =>
        voucher.archived_at ? (
          <div className="flex flex-col gap-0.5 text-12">
            <span className="whitespace-nowrap">{[voucher.archived_by, dateTime(voucher.archived_at)].filter(Boolean).join(' · ')}</span>
            <span className="text-app-muted">{voucher.archive_reason}</span>
          </div>
        ) : (
          <span className="text-12 whitespace-nowrap">{[voucher.uploaded_by, dateTime(voucher.uploaded_at)].filter(Boolean).join(' · ')}</span>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('vouchers.title')}
        subtitle={t('vouchers.subtitle')}
        actions={
          manage ? (
            <button type="button" className={buttonClass('cta')} onClick={() => setUploading(true)}>
              {t('vouchers.new')}
            </button>
          ) : null
        }
      />

      <Chips
        label={t('vouchers.view')}
        value={filters.view}
        onChange={(view) => set({ view })}
        options={VOUCHER_VIEWS.map((value) => ({ value, label: `${t(`vouchers.views.${value}`)}${counts ? ` · ${number(counts[value])}` : ''}` }))}
      />

      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line p-3.5">
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('vouchers.search')} aria-label={t('vouchers.search')} className={controlClass()} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState
            title={filters.search ? t('vouchers.emptyFiltered') : t(`vouchers.empty.${filters.view}`)}
            note={t('vouchers.emptyNote')}
            action={manage && filters.view === 'upcoming' && !filters.search ? <button type="button" className={buttonClass('cta')} onClick={() => setUploading(true)}>{t('vouchers.new')}</button> : undefined}
          />
        ) : (
          <DataTable label={t('vouchers.title')} testId="vouchers-table" columns={columns} rows={list.data.data} rowKey={(voucher) => voucher.id} rowLabel={(voucher) => voucher.title} actions={actionsFor} />
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

      {uploading ? <UploadVoucherDialog onClose={() => setUploading(false)} /> : null}
      {archiving ? <ArchiveVoucherDialog voucher={archiving} onClose={() => setArchiving(null)} /> : null}
    </>
  )
}
