import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice } from '../../components/ui/feedback'
import { Switch } from '../../components/ui/fields'
import { Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { documentName, useStaffDocuments, type StaffDocument, type VaultFilters, type VaultStatus } from './api'
import { ArchiveDocumentDialog, UploadDocumentDialog } from './DocumentDialogs'
import { DocumentStatusBadge, ExpiryNote } from './documentBits'
import { useOpenStaffDocument } from './useOpenStaffDocument'

const STATUSES: VaultStatus[] = ['attention', 'expired', 'expiring', 'renew_soon', 'valid', 'no_expiry', 'all']

/**
 * System → Vault: staff documents with their expiry (docs/phase-7-hr-attendance-bonus-wallet.md §4.2). Files are
 * encrypted and every opening is audited. The sidebar badge opens ?status=attention — expired and expiring documents of
 * staff who aren't suspended — and counts exactly those rows.
 */
export function VaultPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { number } = useFormat()
  const [params, setParams] = useSearchParams()
  const filters: VaultFilters = {
    status: STATUSES.includes(params.get('status') as VaultStatus) ? (params.get('status') as VaultStatus) : 'all',
    archived: params.get('archived') === '1',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const list = useStaffDocuments(filters)
  const open = useOpenStaffDocument()
  const manage = can('staff_documents.manage')
  const [uploading, setUploading] = useState(false)
  const [replacing, setReplacing] = useState<StaffDocument | null>(null)
  const [archiving, setArchiving] = useState<StaffDocument | null>(null)

  const set = (patch: Partial<VaultFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams({ status: next.status })
    if (next.archived) query.set('archived', '1')
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  const actionsFor = (row: StaffDocument): RowAction[] => {
    const cannotManage = !manage ? t('vault.needsManage') : row.archived_at ? t('vault.alreadyArchived') : undefined
    return [
      { key: 'open', icon: '◉', label: t('vault.open'), tone: 'muted', onSelect: () => void open(row.id) },
      { key: 'replace', icon: '⟳', label: t('vault.replace'), tone: 'blue', disabledReason: cannotManage, onSelect: () => setReplacing(row) },
      { key: 'archive', icon: '✕', label: t('vault.archive'), tone: 'red', disabledReason: cannotManage, onSelect: () => setArchiving(row) },
      { key: 'record', icon: '→', label: t('vault.openRecord'), tone: 'blue', to: `/staff/${row.staff.id}`, disabledReason: can('staff.manage') ? undefined : t('vault.needsStaffManage') },
    ]
  }

  const columns: Column<StaffDocument>[] = [
    {
      key: 'document',
      header: t('vault.columns.document'),
      cell: (row) => (
        <div className="flex max-w-80 flex-col gap-0.5">
          <span className="font-medium">{documentName(t, row)}</span>
          <span className="truncate text-12 text-app-muted">{row.number ? `№ ${row.number}` : t('vault.noNumber')}</span>
        </div>
      ),
    },
    {
      key: 'owner',
      header: t('vault.columns.owner'),
      cell: (row) => (
        <div className="flex flex-col gap-0.5">
          <span>{row.staff.name}</span>
          <span className="font-display text-12 text-app-muted">
            {row.staff.employee_code}
            {row.staff.status === 'suspended' ? ` · ${t('staff.status.suspended')}` : ''}
          </span>
        </div>
      ),
    },
    { key: 'expiry', header: t('vault.columns.expiry'), cell: (row) => <ExpiryNote document={row} /> },
    {
      key: 'status',
      header: t('common.status'),
      cell: (row) =>
        row.archived_at ? (
          <span className="flex max-w-64 flex-col gap-0.5 text-12 text-app-muted">
            <span className="font-semibold">{t('vault.archived')}</span>
            <span className="truncate">{t('vault.archivedBecause', { name: row.archived_by ?? '—', reason: row.archive_reason === 'replaced' ? t('vault.replacedReason') : (row.archive_reason ?? '') })}</span>
          </span>
        ) : (
          <DocumentStatusBadge status={row.status} />
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('vault.title')}
        subtitle={t('vault.subtitle')}
        actions={
          manage ? (
            <button type="button" className={buttonClass('cta')} onClick={() => setUploading(true)}>
              {t('vault.add')}
            </button>
          ) : null
        }
      />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Chips
          label={t('common.status')}
          value={filters.status}
          onChange={(status) => set({ status })}
          options={STATUSES.map((value) => ({
            value,
            label: value !== 'all' && list.data && !filters.archived ? `${t(`vault.states.${value}`)} · ${number(list.data.meta.status_counts[value])}` : t(`vault.states.${value}`),
          }))}
        />
        <Switch label={t('vault.showArchived')} checked={filters.archived} onChange={(archived) => set({ archived })} />
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="border-b border-app-line p-3.5">
          <CardTitle bn="স্টাফের ডকুমেন্ট" en="Staff documents" aside={<span className="text-12 text-app-muted">{t('vault.protectedNote')}</span>} />
        </div>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t('vault.empty')} note={filters.status === 'attention' ? t('vault.emptyAttention') : undefined} />
        ) : (
          <DataTable label={t('vault.title')} testId="staff-documents-table" columns={columns} rows={list.data.data} rowKey={(row) => row.id} rowLabel={(row) => `${row.staff.name} · ${documentName(t, row)}`} actions={actionsFor} />
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
      {uploading ? <UploadDocumentDialog onClose={() => setUploading(false)} /> : null}
      {replacing ? <UploadDocumentDialog replacing={replacing} onClose={() => setReplacing(null)} /> : null}
      {archiving ? <ArchiveDocumentDialog document={archiving} onClose={() => setArchiving(null)} /> : null}
    </>
  )
}
