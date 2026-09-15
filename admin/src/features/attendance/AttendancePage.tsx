import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { SelectInput, TextArea, TextInput, Pair } from '../../components/ui/fields'
import { Card, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { usePayrollSheet } from '../payroll/api'
import { roleLabel } from '../staff/api'
import { leaveActions, shiftMonth, useAttendanceChange, useAttendanceMonth, type LeaveFilter, type MonthData } from './api'
import { DayStatusBadge } from './DaysTable'
import { DevicesPanel } from './DeviceCard'
import { LeaveCard } from './LeaveCard'
import { RulesCard } from './RulesCard'

type Row = MonthData['rows'][number]

/**
 * HR → Attendance & salary (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): the device card and sync log, the rules and
 * holidays, the month's table from the biometric punches — with base and payable for those who see payroll (§6) — and
 * leave requests. Adjusting, finalising and paying are on the Salary screen.
 */
export function AttendancePage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { locale, number, bdt, month: monthLabel } = useFormat()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : todayInDhaka().slice(0, 7)
  const leaveStatus = (['pending', 'approved', 'rejected', 'all'] as const).find((value) => value === params.get('status')) ?? 'pending'
  const data = useAttendanceMonth(month)
  const manage = can('attendance.manage')
  const seePay = can('payroll.view') || can('payroll.manage')
  const pay = usePayrollSheet(month, seePay)
  const payFor = new Map((pay.data?.data.rows ?? []).map((row) => [row.staff.id, row]))
  const [recording, setRecording] = useState(false)

  const set = (patch: { month?: string; status?: LeaveFilter }) => {
    const query = new URLSearchParams(params)
    if (patch.month) query.set('month', patch.month)
    if (patch.status) query.set('status', patch.status)
    setParams(query, { replace: true })
  }

  const actionsFor = (row: Row): RowAction[] => [{ key: 'days', icon: '◉', label: t('attendance.openDays'), tone: 'muted', to: `/attendance/staff/${row.staff.id}?month=${month}` }]

  const columns: Column<Row>[] = [
    {
      key: 'staff',
      header: t('attendance.columns.staff'),
      cell: (row) => (
        <span className="flex flex-col">
          <span className="font-medium">{row.staff.name}</span>
          <span className="text-12 text-app-muted">
            {roleLabel(row.staff.role, locale)} · {t('attendance.hoursShort', { n: number(row.totals.hours) })}
          </span>
        </span>
      ),
    },
    { key: 'full', header: t('attendance.columns.full'), align: 'right', cell: (row) => <span className="font-display">{number(row.totals.full)}</span> },
    { key: 'late', header: t('attendance.columns.late'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.late + row.totals.late_early ? 'text-amber' : 'text-app-muted'}`}>{number(row.totals.late + row.totals.late_early)}</span> },
    { key: 'early', header: t('attendance.columns.early'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.early ? 'text-amber' : 'text-app-muted'}`}>{number(row.totals.early)}</span> },
    { key: 'single', header: t('attendance.columns.single'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.single_punch ? 'text-amber' : 'text-app-muted'}`}>{number(row.totals.single_punch)}</span> },
    { key: 'leave', header: t('attendance.columns.leave'), align: 'right', cell: (row) => <span className="font-display">{number(row.totals.leave_paid + row.totals.leave_unpaid)}</span> },
    { key: 'absent', header: t('attendance.columns.absent'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.absent ? 'text-red' : 'text-app-muted'}`}>{number(row.totals.absent)}</span> },
    // The design's salary columns, for those who see payroll (§6): the month's sheet, live until it is finalised.
    ...(seePay
      ? [
          {
            key: 'base',
            header: t('payroll.columns.base'),
            align: 'right' as const,
            cell: (row: Row) => {
              const base = payFor.get(row.staff.id)?.base
              if (base === undefined) return null
              return base === null ? <span className="text-12 text-app-muted">{t('payroll.noSalary')}</span> : <span className="font-display text-app-muted">{bdt(base)}</span>
            },
          },
          {
            key: 'payable',
            header: t('payroll.columns.payable'),
            align: 'right' as const,
            cell: (row: Row) => {
              const figures = payFor.get(row.staff.id)?.figures
              return figures ? (
                <span className="flex flex-col items-end leading-1.2">
                  <span className="font-display font-bold">{bdt(figures.payable)}</span>
                  <span className="flex gap-1.5 text-11 whitespace-nowrap">
                    {figures.deductions > 0 ? <span className="text-red">{t('payroll.cut', { amount: bdt(figures.deductions) })}</span> : null}
                    {figures.adjustments !== 0 ? <span className={figures.adjustments < 0 ? 'text-red' : 'text-green'}>{figures.adjustments < 0 ? `− ${bdt(Math.abs(figures.adjustments))}` : `+ ${bdt(figures.adjustments)}`}</span> : null}
                  </span>
                </span>
              ) : null
            },
          },
        ]
      : []),
    ...(month === todayInDhaka().slice(0, 7)
      ? [{ key: 'today', header: t('attendance.columns.today'), cell: (row: Row) => (row.today ? <DayStatusBadge day={row.today} /> : null) }]
      : []),
  ]

  return (
    <>
      <PageHeader
        title={t('attendance.title')}
        subtitle={t('attendance.subtitle')}
        actions={
          <div className="flex items-center gap-2">
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => set({ month: shiftMonth(month, -1) })} aria-label={t('attendance.previousMonth')}>
              ←
            </button>
            <span className="min-w-36 text-center font-display text-14 font-semibold" data-testid="attendance-month">
              {monthLabel(month)}
            </span>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => set({ month: shiftMonth(month, 1) })} aria-label={t('attendance.nextMonth')}>
              →
            </button>
          </div>
        }
      />

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <DevicesPanel />
        {data.data ? <RulesCard month={month} rules={data.data.data.rules} manage={manage} /> : <Loading />}
      </div>

      {data.isPending ? (
        <Loading />
      ) : data.isError ? (
        <ErrorNotice error={data.error} />
      ) : (
        <>
          <div className="grid-auto-fit-200 grid gap-3" data-testid="attendance-kpis">
            {[
              [t('attendance.kpis.attendance'), data.data.data.kpis.attendance_percent === null ? '—' : `${number(data.data.data.kpis.attendance_percent)}%`, t('attendance.kpis.attendanceNote')],
              [t('attendance.kpis.reduced'), number(data.data.data.kpis.reduced_days), t('attendance.kpis.reducedNote')],
              [t('attendance.kpis.absent'), number(data.data.data.kpis.absent_days), t('attendance.kpis.absentNote')],
              [t('attendance.kpis.pendingLeave'), number(data.data.data.kpis.pending_leave), t('attendance.kpis.pendingLeaveNote')],
            ].map(([label, value, note]) => (
              <Card key={label}>
                <span className="text-12 text-app-muted">{label}</span>
                <span className="font-display text-22 font-bold">{value}</span>
                <span className="text-12 text-app-muted">{note}</span>
              </Card>
            ))}
          </div>
          <Card padded={false} className="overflow-hidden">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-app-line p-3.5">
              <h2 className="m-0 text-15 font-semibold">{t('attendance.monthTitle', { month: monthLabel(month) })}</h2>
              <span className="flex flex-wrap items-center gap-3">
                <span className="text-12 text-app-muted">{t('attendance.rules.note', { start: data.data.data.rules.duty_start, end: data.data.data.rules.duty_end, grace: number(data.data.data.rules.grace_minutes), percent: number(data.data.data.rules.late_early_pay_percent) })}</span>
                {seePay ? (
                  <Link to={`/payroll?month=${month}`} className={buttonClass('outline', 'sm')}>
                    {t('payroll.openSheet')}
                  </Link>
                ) : null}
              </span>
            </div>
            {data.data.data.rows.length === 0 ? (
              <EmptyState title={t('attendance.noStaff')} />
            ) : (
              <DataTable label={t('attendance.monthTitle', { month: monthLabel(month) })} testId="attendance-month-table" columns={columns} rows={data.data.data.rows} rowKey={(row) => row.staff.id} rowLabel={(row) => row.staff.name} actions={actionsFor} onRowClick={(row) => navigate(`/attendance/staff/${row.staff.id}?month=${month}`)} />
            )}
          </Card>
        </>
      )}

      {manage ? (
        <>
          <div className="flex justify-end">
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setRecording(true)}>
              {t('leave.recordForSomeone')}
            </button>
          </div>
          <LeaveCard status={leaveStatus} onStatus={(status) => set({ status })} />
        </>
      ) : null}
      {recording && data.data ? <RecordLeaveDialog people={data.data.data.rows.map((row) => row.staff)} onClose={() => setRecording(false)} /> : null}
    </>
  )
}

function RecordLeaveDialog({ people, onClose }: { people: MonthData['rows'][number]['staff'][]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({ staff_id: '', starts_on: todayInDhaka(), ends_on: todayInDhaka(), reason: '' })
  const record = useAttendanceChange(leaveActions.record)
  const fieldError = (name: string) => (record.error instanceof ApiError ? record.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('leave.recordTitle')}>
      <SelectInput label={t('leave.columns.staff')} value={form.staff_id} onChange={(staff_id) => setForm({ ...form, staff_id })} options={[{ value: '', label: t('leave.pickStaff') }, ...people.map((person) => ({ value: String(person.id), label: `${person.name} · ${person.employee_code}` }))]} error={fieldError('staff_id')} />
      <Pair>
        <TextInput label={t('leave.from')} type="date" value={form.starts_on} onChange={(starts_on) => setForm({ ...form, starts_on })} error={fieldError('starts_on')} />
        <TextInput label={t('leave.to')} type="date" value={form.ends_on} onChange={(ends_on) => setForm({ ...form, ends_on })} error={fieldError('ends_on')} />
      </Pair>
      <TextArea label={t('leave.reason')} value={form.reason} onChange={(reason) => setForm({ ...form, reason })} error={fieldError('reason')} rows={2} maxLength={500} />
      {record.error && !(record.error instanceof ApiError && record.error.status === 422) ? <ErrorNotice error={record.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!form.staff_id || form.reason.trim().length < 3 || record.isPending}
          onClick={() => record.mutate({ ...form, staff_id: Number(form.staff_id), reason: form.reason.trim() }, { onSuccess: () => { toast(t('leave.recorded')); onClose() } })}
        >
          {t('leave.record')}
        </button>
      </div>
    </Dialog>
  )
}
