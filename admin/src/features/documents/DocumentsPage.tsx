import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { useDocumentReviews, useReviewDocument, type DocumentReview, type ReviewFilters, type ReviewStatus } from './api'
import { RejectDocumentDialog } from './DocumentReview'
import { useOpenDocument } from './useOpenDocument'

const STATUSES: ReviewStatus[] = ['uploaded', 'rejected', 'verified']

/**
 * Documents — passport scans and photos customers uploaded in the portal, and scans from website bookings, oldest first
 * (docs/phase-6-customer-portal.md §3.3). Staff open each file, then verify or reject with a reason the customer reads.
 * The sidebar badge opens ?status=uploaded, the rows it counts.
 */
export function DocumentsPage() {
  const { t } = useTranslation()
  const { date, dateTime, number, relativeAge } = useFormat()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const filters: ReviewFilters = {
    status: STATUSES.includes(params.get('status') as ReviewStatus) ? (params.get('status') as ReviewStatus) : 'uploaded',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const list = useDocumentReviews(filters)
  const review = useReviewDocument()
  const open = useOpenDocument()
  const [rejecting, setRejecting] = useState<DocumentReview | null>(null)

  const set = (patch: Partial<ReviewFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams({ status: next.status })
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (row: DocumentReview): RowAction[] => {
    const cannot = row.status !== 'uploaded' ? t('documents.alreadyReviewed') : row.actions.review ? undefined : t('documents.cannotReview')
    return [
      { key: 'view', icon: '◉', label: t('documents.open'), tone: 'muted', onSelect: () => void open(row.id) },
      {
        key: 'verify',
        icon: '✓',
        label: t('documents.verify'),
        tone: 'green',
        disabledReason: cannot,
        onSelect: () =>
          review.mutate({ id: row.id, decision: 'verified' }, { onSuccess: () => toast(t('documents.verified', { name: row.traveller.full_name })), onError: (error) => toast(error.message, 'error') }),
      },
      { key: 'reject', icon: '✕', label: t('documents.reject'), tone: 'red', disabledReason: cannot, onSelect: () => setRejecting(row) },
      { key: 'booking', icon: '→', label: t('documents.openBooking'), tone: 'blue', to: `/bookings/${row.booking.id}` },
    ]
  }

  const columns: Column<DocumentReview>[] = [
    {
      key: 'traveller',
      header: t('documents.traveller'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span className="font-medium">{row.traveller.full_name}</span>
          <span className="text-12 text-app-muted">
            {t(`documents.kinds.${row.kind}`)} · {t(`documents.sources.${row.source ?? 'portal'}`)}
          </span>
        </div>
      ),
    },
    {
      key: 'booking',
      header: t('documents.booking'),
      cell: (row) => (
        <div className="flex max-w-72 flex-col">
          <span className="font-display text-13">{row.booking.reference}</span>
          <span className="truncate text-12 text-app-muted">
            {row.booking.package_title_en}
            {row.booking.travel_start ? ` · ${date(row.booking.travel_start)}` : ''}
          </span>
        </div>
      ),
    },
    { key: 'owner', header: t('ownership.owner'), cell: (row) => <span className="text-13">{row.booking.assigned_staff?.name ?? t('ownership.pool')}</span> },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) => (
        <span className="flex flex-col items-start gap-0.5">
          <Badge tone={row.status === 'verified' ? 'green' : row.status === 'rejected' ? 'red' : 'orange'}>{t(`documents.status.${row.status}`)}</Badge>
          <span className="text-12 text-app-muted">
            {row.status === 'uploaded' && row.uploaded_at
              ? relativeAge(Math.floor((Date.now() - Date.parse(row.uploaded_at)) / 60_000))
              : row.reviewed_at
                ? `${row.reviewed_by?.name ?? ''} · ${dateTime(row.reviewed_at)}`
                : ''}
          </span>
          {row.status === 'rejected' && row.note ? <span className="max-w-60 truncate text-12 text-red">{row.note}</span> : null}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('documents.title')} subtitle={t('documents.subtitle')} />
      <Chips label={t('common.status')} value={filters.status} onChange={(status) => set({ status })} options={STATUSES.map((value) => ({ value, label: t(`documents.states.${value}`) }))} />

      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line p-3.5">
          <CardTitle bn="যাত্রীর কাগজপত্র" en="Traveller documents" aside={<span className="text-12 text-app-muted">{t('documents.queueNote')}</span>} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('documents.empty')} note={t('documents.emptyNote')} />
        ) : (
          <DataTable label={t('documents.title')} testId="document-reviews-table" columns={columns} rows={list.data.data} rowKey={(row) => row.id} rowLabel={(row) => `${row.traveller.full_name} · ${t(`documents.kinds.${row.kind}`)}`} actions={actionsFor} />
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
      <RejectDocumentDialog id={rejecting?.id ?? null} name={rejecting?.traveller.full_name ?? ''} onClose={() => setRejecting(null)} />
    </>
  )
}
