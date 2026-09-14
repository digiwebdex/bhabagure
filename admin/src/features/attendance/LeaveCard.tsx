import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Switch, TextArea } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { leaveActions, useAttendanceChange, useLeaveRequests, type LeaveFilter, type LeaveRow, type LeaveStatus } from './api'

const FILTERS: LeaveFilter[] = ['pending', 'approved', 'rejected', 'all']
const TONES: Record<LeaveStatus, 'orange' | 'green' | 'red' | 'slate'> = { pending: 'orange', approved: 'green', rejected: 'red', cancelled: 'slate', revoked: 'slate' }

export function LeaveStatusBadge({ leave }: { leave: Pick<LeaveRow, 'status' | 'paid'> }) {
  const { t } = useTranslation()
  return <Badge tone={TONES[leave.status]}>{leave.status === 'approved' && !leave.paid ? t('leave.approvedUnpaid') : t(`leave.status.${leave.status}`)}</Badge>
}

/**
 * The design's leave requests card, as a queue for attendance.manage (docs/phase-7-hr-attendance-bonus-wallet.md §5.1).
 * The HR badge opens ?status=pending and counts exactly those rows.
 */
export function LeaveCard({ status, onStatus }: { status: LeaveFilter; onStatus: (status: LeaveFilter) => void }) {
  const { t } = useTranslation()
  const { date, number } = useFormat()
  const [page, setPage] = useState(1)
  const list = useLeaveRequests(status, page)
  const [deciding, setDeciding] = useState<{ leave: LeaveRow; action: 'approve' | 'reject' | 'revoke' } | null>(null)

  const actionsFor = (leave: LeaveRow): RowAction[] => {
    const undecided = leave.status === 'pending' ? undefined : t('leave.alreadyDecided')
    return [
      { key: 'approve', icon: '✓', label: t('leave.approve'), tone: 'green', disabledReason: undecided, onSelect: () => setDeciding({ leave, action: 'approve' }) },
      { key: 'reject', icon: '✕', label: t('leave.reject'), tone: 'red', disabledReason: undecided, onSelect: () => setDeciding({ leave, action: 'reject' }) },
      { key: 'revoke', icon: '↺', label: t('leave.revoke'), tone: 'amber', disabledReason: leave.status === 'approved' ? undefined : t('leave.onlyApproved'), onSelect: () => setDeciding({ leave, action: 'revoke' }) },
      { key: 'days', icon: '→', label: t('leave.openDays'), tone: 'blue', to: `/attendance/staff/${leave.staff.id}?month=${leave.starts_on.slice(0, 7)}` },
    ]
  }

  const columns: Column<LeaveRow>[] = [
    {
      key: 'staff',
      header: t('leave.columns.staff'),
      cell: (leave) => (
        <span className="flex flex-col">
          <span className="font-medium">{leave.staff.name}</span>
          <span className="text-12 text-app-muted">{leave.filed_for_someone ? t('leave.recordedBy', { name: leave.filed_by ?? '—' }) : t('leave.filedThemselves')}</span>
        </span>
      ),
    },
    {
      key: 'dates',
      header: t('leave.columns.dates'),
      cell: (leave) => (
        <span className="flex flex-col">
          <span className="font-display">{leave.starts_on === leave.ends_on ? date(leave.starts_on) : `${date(leave.starts_on)} – ${date(leave.ends_on)}`}</span>
          <span className="text-12 text-app-muted">{t('leave.workingDays', { count: leave.working_days, n: number(leave.working_days) })}</span>
        </span>
      ),
    },
    { key: 'reason', header: t('leave.columns.reason'), cell: (leave) => <span className="block max-w-80 text-13 whitespace-normal">{leave.reason}</span> },
    {
      key: 'status',
      header: t('common.status'),
      cell: (leave) => (
        <span className="flex flex-col items-start gap-0.5">
          <LeaveStatusBadge leave={leave} />
          {leave.decision_note ? <span className="max-w-60 truncate text-12 text-app-muted">{leave.decision_note}</span> : null}
        </span>
      ),
    },
  ]

  return (
    <Card padded={false} className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-app-line p-3.5">
        <CardTitle bn="ছুটির আবেদন" en="Leave requests" />
        <Chips label={t('common.status')} value={status} onChange={(value) => { setPage(1); onStatus(value) }} options={FILTERS.map((value) => ({ value, label: t(`leave.filters.${value}`) }))} />
      </div>
      {list.isPending ? (
        <Loading />
      ) : list.isError ? (
        <div className="p-4">
          <ErrorNotice error={list.error} />
        </div>
      ) : list.data.data.length === 0 ? (
        <EmptyState title={status === 'pending' ? t('leave.nonePending') : t('leave.none')} />
      ) : (
        <DataTable label={t('leave.title')} testId="leave-requests-table" columns={columns} rows={list.data.data} rowKey={(leave) => leave.id} rowLabel={(leave) => `${leave.staff.name ?? ''} · ${date(leave.starts_on)}`} actions={actionsFor} />
      )}
      {list.data && list.data.meta.last_page > 1 ? (
        <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
          <button type="button" className={buttonClass('outline', 'sm')} disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('common.previous')}
          </button>
          <span className="text-app-muted">{t('common.pageOf', { page: number(page), last: number(list.data.meta.last_page) })}</span>
          <button type="button" className={buttonClass('outline', 'sm')} disabled={page >= list.data.meta.last_page} onClick={() => setPage(page + 1)}>
            {t('common.next')}
          </button>
        </div>
      ) : null}
      {deciding ? <DecideDialog leave={deciding.leave} action={deciding.action} onClose={() => setDeciding(null)} /> : null}
    </Card>
  )
}

function DecideDialog({ leave, action, onClose }: { leave: LeaveRow; action: 'approve' | 'reject' | 'revoke'; onClose: () => void }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const toast = useToast()
  const [paid, setPaid] = useState(true)
  const [note, setNote] = useState('')
  const approve = useAttendanceChange(leaveActions.approve)
  const reject = useAttendanceChange(leaveActions.reject)
  const revoke = useAttendanceChange(leaveActions.revoke)
  const pending = approve.isPending || reject.isPending || revoke.isPending
  const error = approve.error ?? reject.error ?? revoke.error
  const needsNote = action !== 'approve'
  const done = () => {
    toast(t(`leave.done.${action}`, { name: leave.staff.name ?? '' }))
    onClose()
  }

  return (
    <Dialog open onClose={onClose} title={t(`leave.titles.${action}`, { name: leave.staff.name ?? '' })}>
      <p className="m-0 text-13.5 leading-1.6">
        <span className="font-display">{leave.starts_on === leave.ends_on ? date(leave.starts_on) : `${date(leave.starts_on)} – ${date(leave.ends_on)}`}</span> · {leave.reason}
      </p>
      {action === 'approve' ? <Switch label={t('leave.paid')} hint={t('leave.paidHint')} checked={paid} onChange={setPaid} /> : null}
      <TextArea label={needsNote ? t('leave.why') : t('leave.noteOptional')} value={note} onChange={setNote} rows={2} maxLength={300} />
      {error ? <ErrorNotice error={error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass(action === 'approve' ? 'success' : 'danger')}
          disabled={pending || (needsNote && note.trim().length < 3)}
          onClick={() => {
            if (action === 'approve') approve.mutate({ id: leave.id, paid, note: note.trim() }, { onSuccess: done })
            else if (action === 'reject') reject.mutate({ id: leave.id, note: note.trim() }, { onSuccess: done })
            else revoke.mutate({ id: leave.id, note: note.trim() }, { onSuccess: done })
          }}
        >
          {t(`leave.${action}`)}
        </button>
      </div>
    </Dialog>
  )
}
