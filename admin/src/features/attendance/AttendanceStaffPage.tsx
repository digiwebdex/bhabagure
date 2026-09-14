import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'

import { useAuth, useStaff } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { roleLabel } from '../staff/api'
import { attendanceActions, shiftMonth, useAttendanceChange, usePersonMonth, type Correction, type Day, type PersonMonth, type Totals } from './api'
import { DaysTable } from './DaysTable'
import { LeaveStatusBadge } from './LeaveCard'

/** One person's month (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): every day, the corrections made, and their leave. */
export function AttendanceStaffPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const me = useStaff()
  const { locale } = useFormat()
  const id = Number(useParams().id)
  const [params, setParams] = useSearchParams()
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : todayInDhaka().slice(0, 7)
  const data = usePersonMonth(id, month)
  const [correcting, setCorrecting] = useState<Day | null>(null)
  // Nobody corrects their own attendance (the API refuses it too), except the super admin.
  const manage = can('attendance.manage') && (id !== me.id || me.is_super_admin)

  if (data.isPending) return <Loading />
  if (data.isError) return data.error instanceof ApiError && data.error.status === 404 ? <EmptyState title={t('errors.notFoundTitle')} /> : <ErrorNotice error={data.error} />
  const person = data.data.data

  return (
    <>
      <Link to={`/attendance?month=${month}`} className="self-start text-13 font-semibold text-blue">
        {t('attendance.back')}
      </Link>
      <PageHeader
        title={person.staff.name}
        subtitle={`${roleLabel(person.staff.role, locale)} · ${person.staff.employee_code}`}
        actions={<MonthSwitch month={month} onChange={(value) => setParams({ month: value }, { replace: true })} />}
      />
      <TotalsStrip totals={person.totals} />
      <div className="grid items-start gap-4.5 xl:grid-cols-[minmax(0,1.6fr)_minmax(280px,1fr)]">
        <Card padded={false} className="overflow-hidden">
          <div className="border-b border-app-line p-3.5">
            <CardTitle bn="দৈনিক পাঞ্চ" en="Daily punches" aside={<span className="text-12 text-app-muted">{t('attendance.fromDevice')}</span>} />
          </div>
          <DaysTable
            days={person.days}
            testId="attendance-days-table"
            actions={
              manage
                ? (day) => [
                    {
                      key: 'correct',
                      icon: '✎',
                      label: t('attendance.correct'),
                      tone: 'blue',
                      disabledReason: day.date > todayInDhaka() ? t('attendance.notYet') : day.status === 'not_employed' ? t('attendance.notEmployed') : undefined,
                      onSelect: () => setCorrecting(day),
                    },
                  ]
                : undefined
            }
          />
        </Card>
        <div className="flex flex-col gap-4.5">
          <CorrectionsCard person={person} manage={manage} />
          <Card>
            <CardTitle bn="ছুটি" en="Leave" />
            {person.leave.length === 0 ? (
              <p className="m-0 text-13 text-app-muted">{t('leave.noneThisMonth')}</p>
            ) : (
              <ul className="m-0 flex list-none flex-col gap-2 p-0 text-13">
                {person.leave.map((leave) => (
                  <li key={leave.id} className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                      {leave.starts_on} – {leave.ends_on} · {leave.reason}
                    </span>
                    <LeaveStatusBadge leave={leave} />
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
      {correcting ? <CorrectionDialog person={person} day={correcting} onClose={() => setCorrecting(null)} /> : null}
    </>
  )
}

export function MonthSwitch({ month, onChange }: { month: string; onChange: (month: string) => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex items-center gap-2">
      <button type="button" className={buttonClass('outline', 'sm')} onClick={() => onChange(shiftMonth(month, -1))} aria-label={t('attendance.previousMonth')}>
        ←
      </button>
      <span className="min-w-28 text-center font-display text-14 font-semibold">{month}</span>
      <button type="button" className={buttonClass('outline', 'sm')} onClick={() => onChange(shiftMonth(month, 1))} aria-label={t('attendance.nextMonth')}>
        →
      </button>
    </div>
  )
}

export function TotalsStrip({ totals }: { totals: Totals }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const items: [string, number][] = [
    [t('attendance.columns.full'), totals.full],
    [t('attendance.columns.late'), totals.late + totals.late_early],
    [t('attendance.columns.early'), totals.early],
    [t('attendance.columns.single'), totals.single_punch],
    [t('attendance.columns.leave'), totals.leave_paid + totals.leave_unpaid],
    [t('attendance.columns.absent'), totals.absent],
    [t('attendance.columns.workingDays'), totals.working_days],
    [t('attendance.columns.hours'), totals.hours],
  ]
  return (
    <div className="flex flex-wrap gap-2" data-testid="attendance-totals">
      {items.map(([label, value]) => (
        <span key={label} className="rounded-10 bg-app-surface-2 px-3 py-1.5 text-13">
          <span className="text-app-muted">{label}</span> <span className="font-display font-semibold">{number(value)}</span>
        </span>
      ))}
    </div>
  )
}

function CorrectionsCard({ person, manage }: { person: PersonMonth; manage: boolean }) {
  const { t } = useTranslation()
  const { date, digits } = useFormat()
  const toast = useToast()
  const [reversing, setReversing] = useState<Correction | null>(null)
  const [reason, setReason] = useState('')
  const reverse = useAttendanceChange(attendanceActions.reverse)
  const reversed = new Set(person.corrections.filter((correction) => correction.reverses_id).map((correction) => correction.reverses_id))

  return (
    <Card>
      <CardTitle bn="সংশোধন" en="Corrections" />
      {person.corrections.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('attendance.noCorrections')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0 text-13" data-testid="attendance-corrections">
          {person.corrections.map((correction) => (
            <li key={correction.id} className={`flex flex-col gap-0.5 rounded-10 bg-app-surface-2 px-3 py-2 ${reversed.has(correction.id) ? 'opacity-60' : ''}`}>
              <span className={`font-medium ${reversed.has(correction.id) ? 'line-through' : ''}`}>
                {date(correction.work_date)} · {t(`attendance.correctionKinds.${correction.kind}`, { time: correction.time ? digits(correction.time) : '' })}
              </span>
              <span className="text-12 text-app-muted">
                {correction.reason}
                {correction.by ? ` · ${correction.by}` : ''}
              </span>
              {manage && correction.kind !== 'reversal' && !reversed.has(correction.id) ? (
                <button type="button" className="cursor-pointer self-start text-12 font-semibold text-red" onClick={() => setReversing(correction)}>
                  {t('attendance.undo')}
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      <Dialog open={reversing !== null} onClose={() => setReversing(null)} title={t('attendance.undoTitle')}>
        <TextArea label={t('attendance.why')} value={reason} onChange={setReason} rows={2} maxLength={300} />
        {reverse.error ? <ErrorNotice error={reverse.error} /> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={() => setReversing(null)}>
            {t('common.cancel')}
          </button>
          <button
            type="button"
            className={buttonClass('danger')}
            disabled={reason.trim().length < 3 || reverse.isPending}
            onClick={() =>
              reversing &&
              reverse.mutate({ id: reversing.id, reason: reason.trim() }, { onSuccess: () => { toast(t('attendance.undone')); setReversing(null); setReason('') } })
            }
          >
            {t('attendance.undo')}
          </button>
        </div>
      </Dialog>
    </Card>
  )
}

function CorrectionDialog({ person, day, onClose }: { person: PersonMonth; day: Day; onClose: () => void }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const toast = useToast()
  const [kind, setKind] = useState<'in' | 'out' | 'worked'>(day.in === null ? 'in' : 'out')
  const [time, setTime] = useState(kind === 'in' ? person.rules.duty_start : person.rules.duty_end)
  const [reason, setReason] = useState('')
  const correct = useAttendanceChange(attendanceActions.correct)
  const fieldError = (name: string) => (correct.error instanceof ApiError ? correct.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('attendance.correctTitle', { date: date(day.date) })}>
      <p className="m-0 text-13 text-app-muted">{day.punches.length ? t('attendance.punchesWere', { punches: day.punches.join(', ') }) : t('attendance.noPunches')}</p>
      <SelectInput
        label={t('attendance.correctionKind')}
        value={kind}
        onChange={(value) => {
          const next = value as 'in' | 'out' | 'worked'
          setKind(next)
          if (next === 'in') setTime(person.rules.duty_start)
          if (next === 'out') setTime(person.rules.duty_end)
        }}
        options={(['in', 'out', 'worked'] as const).map((value) => ({ value, label: t(`attendance.correctionOptions.${value}`) }))}
      />
      {kind !== 'worked' ? <TextInput label={t('attendance.time')} type="time" value={time} onChange={setTime} error={fieldError('time')} /> : null}
      <TextArea label={t('attendance.why')} value={reason} onChange={setReason} error={fieldError('reason')} rows={2} maxLength={300} />
      {correct.error && !(correct.error instanceof ApiError && correct.error.status === 422) ? <ErrorNotice error={correct.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={reason.trim().length < 3 || (kind !== 'worked' && !time) || correct.isPending}
          onClick={() =>
            correct.mutate(
              { staff_id: person.staff.id, work_date: day.date, kind, time: kind === 'worked' ? null : time, reason: reason.trim() },
              { onSuccess: () => { toast(t('attendance.correctedToast')); onClose() } },
            )
          }
        >
          {t('attendance.saveCorrection')}
        </button>
      </div>
    </Dialog>
  )
}
