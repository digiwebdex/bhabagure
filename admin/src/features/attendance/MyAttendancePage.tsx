import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Pair, TextArea, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { MyPayslipsCard } from '../payroll/MyPayslipsCard'
import { myLeaveActions, useAttendanceChange, useMyLeave, useMyMonth } from './api'
import { MonthSwitch, TotalsStrip } from './AttendanceStaffPage'
import { DaysTable } from './DaysTable'
import { LeaveStatusBadge } from './LeaveCard'

/**
 * My attendance (docs/phase-7-hr-attendance-bonus-wallet.md §5.1), for every staff member: their own days from the
 * device, their leave requests — filed here, cancelled while nobody has decided them — and their payslips (§6).
 */
export function MyAttendancePage() {
  const { t } = useTranslation()
  const { date, number } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const [params, setParams] = useSearchParams()
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : todayInDhaka().slice(0, 7)
  const data = useMyMonth(month)
  const leave = useMyLeave()
  const cancel = useAttendanceChange(myLeaveActions.cancel)
  const [filing, setFiling] = useState(false)

  return (
    <>
      <PageHeader title={t('myAttendance.title')} subtitle={t('myAttendance.subtitle')} actions={<MonthSwitch month={month} onChange={(value) => setParams({ month: value }, { replace: true })} />} />
      {data.isPending ? (
        <Loading />
      ) : data.isError ? (
        <ErrorNotice error={data.error} />
      ) : (
        <>
          <TotalsStrip totals={data.data.data.totals} />
          <p className="m-0 text-12.5 text-app-muted">{t('attendance.rules.note', { start: data.data.data.rules.duty_start, end: data.data.data.rules.duty_end, grace: number(data.data.data.rules.grace_minutes), percent: number(data.data.data.rules.late_early_pay_percent) })}</p>
        </>
      )}
      <div className="grid items-start gap-4.5 xl:grid-cols-[minmax(0,1.6fr)_minmax(280px,1fr)]">
        <Card padded={false} className="overflow-hidden">
          <div className="border-b border-app-line p-3.5">
            <CardTitle bn="আমার পাঞ্চ" en="My punches" aside={<span className="text-12 text-app-muted">{t('myAttendance.askHr')}</span>} />
          </div>
          {data.data ? <DaysTable days={data.data.data.days} testId="my-days-table" /> : <Loading />}
        </Card>
        <div className="flex flex-col gap-4.5">
          <Card>
            <CardTitle
              bn="আমার ছুটি"
              en="My leave"
              aside={
                <button type="button" className={buttonClass('cta', 'sm')} onClick={() => setFiling(true)}>
                  {t('myAttendance.askLeave')}
                </button>
              }
            />
            {leave.isPending ? (
              <Loading />
            ) : leave.isError ? (
              <ErrorNotice error={leave.error} />
            ) : leave.data.data.length === 0 ? (
              <p className="m-0 text-13 text-app-muted">{t('myAttendance.noLeave')}</p>
            ) : (
              <ul className="m-0 flex list-none flex-col gap-2 p-0 text-13" data-testid="my-leave">
                {leave.data.data.map((request) => (
                  <li key={request.id} className="flex flex-col gap-1 rounded-10 bg-app-surface-2 px-3 py-2">
                    <span className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-display">{request.starts_on === request.ends_on ? date(request.starts_on) : `${date(request.starts_on)} – ${date(request.ends_on)}`}</span>
                      <LeaveStatusBadge leave={request} />
                    </span>
                    <span className="text-app-muted">{request.reason}</span>
                    {request.decision_note ? <span className="text-12">{t('myAttendance.note', { name: request.decided_by ?? '—', note: request.decision_note })}</span> : null}
                    {request.status === 'pending' ? (
                      <button
                        type="button"
                        className="cursor-pointer self-start text-12 font-semibold text-red"
                        disabled={cancel.isPending}
                        onClick={async () => {
                          if (await confirm(t('myAttendance.cancelConfirm'))) cancel.mutate(request.id, { onSuccess: () => toast(t('myAttendance.cancelled')) })
                        }}
                      >
                        {t('myAttendance.cancel')}
                      </button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <MyPayslipsCard />
        </div>
      </div>
      {filing ? <LeaveDialog onClose={() => setFiling(false)} /> : null}
      {element}
    </>
  )
}

function LeaveDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({ starts_on: todayInDhaka(), ends_on: todayInDhaka(), reason: '' })
  const file = useAttendanceChange(myLeaveActions.file)
  const fieldError = (name: string) => (file.error instanceof ApiError ? file.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('myAttendance.askLeaveTitle')}>
      <Pair>
        <TextInput label={t('leave.from')} type="date" value={form.starts_on} onChange={(starts_on) => setForm({ ...form, starts_on, ends_on: form.ends_on < starts_on ? starts_on : form.ends_on })} error={fieldError('starts_on')} />
        <TextInput label={t('leave.to')} type="date" value={form.ends_on} onChange={(ends_on) => setForm({ ...form, ends_on })} error={fieldError('ends_on')} />
      </Pair>
      <TextArea label={t('leave.reason')} value={form.reason} onChange={(reason) => setForm({ ...form, reason })} error={fieldError('reason')} rows={3} maxLength={500} />
      {file.error && !(file.error instanceof ApiError && file.error.status === 422) ? <ErrorNotice error={file.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={form.reason.trim().length < 3 || file.isPending} onClick={() => file.mutate({ ...form, reason: form.reason.trim() }, { onSuccess: () => { toast(t('myAttendance.filed')); onClose() } })}>
          {t('myAttendance.send')}
        </button>
      </div>
    </Dialog>
  )
}
