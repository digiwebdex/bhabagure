import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { useAuth, useStaff } from '../../app/auth'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { shiftMonth } from '../attendance/api'
import { MonthSwitch } from '../attendance/AttendanceStaffPage'
import { roleLabel } from '../staff/api'
import { payrollActions, payslipPath, useOpenPayslip, usePayrollChange, usePayrollSheet, type PayrollRow, type PayrollSheet } from './api'
import { AdjustDialog, FinaliseDialog, PayDialog, ReopenDialog, SalaryDialog } from './PayrollDialogs'

type Open = { kind: 'adjust' | 'pay' | 'salary'; row: PayrollRow } | { kind: 'finalise' | 'reopen' } | null

/**
 * HR → Salary (docs/phase-7-hr-attendance-bonus-wallet.md §6): a month's pay from attendance — the design's "Monthly
 * attendance & salary" table with base, cut and payable — adjustments, finalising (figures frozen, payslips emailed),
 * paying each person as a Salaries cash-out, and payslips. Defaults to last month, the one normally being paid.
 */
export function PayrollPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : shiftMonth(todayInDhaka().slice(0, 7), -1)
  const sheet = usePayrollSheet(month)

  return (
    <>
      <PageHeader title={t('payroll.title')} subtitle={t('payroll.subtitle')} actions={<MonthSwitch month={month} onChange={(value) => setParams({ month: value }, { replace: true })} />} />
      {sheet.isPending ? <Loading /> : sheet.isError ? <ErrorNotice error={sheet.error} /> : <Sheet sheet={sheet.data.data} />}
    </>
  )
}

function Sheet({ sheet }: { sheet: PayrollSheet }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const me = useStaff()
  const { locale, bdt, number, date, month: monthLabel } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const openPayslip = useOpenPayslip()
  const removeAdjustment = usePayrollChange(payrollActions.removeAdjustment)
  const [open, setOpen] = useState<Open>(null)
  const finalised = sheet.status === 'finalised'
  const seeDays = can('attendance.view_all') || can('attendance.manage')
  // Nobody adjusts or pays themselves (the API refuses it too), except the super admin.
  const own = (row: PayrollRow) => row.staff.id === me.id && !me.is_super_admin
  const signed = (amount: number) => (amount < 0 ? `− ${bdt(Math.abs(amount))}` : `+ ${bdt(amount)}`)
  const onPayroll = sheet.rows.filter((row) => row.base !== null)
  const adjustments = sheet.rows.flatMap((row) => row.adjustments.map((adjustment) => ({ row, adjustment })))

  const actionsFor = (row: PayrollRow): RowAction[] => [
    ...(seeDays ? [{ key: 'days', icon: '◉', label: t('payroll.days'), tone: 'muted' as const, to: `/attendance/staff/${row.staff.id}?month=${sheet.month}` }] : []),
    { key: 'salary', icon: '$', label: t('payroll.salary'), tone: 'blue', onSelect: () => setOpen({ kind: 'salary', row }) },
    ...(!finalised && can('payroll.manage')
      ? [
          {
            key: 'adjust',
            icon: '±',
            label: t('payroll.adjust'),
            tone: 'purple' as const,
            disabledReason: own(row) ? t('payroll.ownPay') : row.base === null ? t('payroll.salaryFirst') : undefined,
            onSelect: () => setOpen({ kind: 'adjust', row }),
          },
        ]
      : []),
    ...(finalised && sheet.actions.pay
      ? [
          {
            key: 'pay',
            icon: '✓',
            label: t('payroll.pay'),
            tone: 'green' as const,
            disabledReason: row.paid_at ? t('payroll.alreadyPaid') : own(row) ? t('payroll.ownPay') : (row.figures?.payable ?? 0) <= 0 ? t('payroll.nothingToPay') : undefined,
            onSelect: () => setOpen({ kind: 'pay', row }),
          },
        ]
      : []),
    ...(finalised && row.item_id !== null ? [{ key: 'payslip', icon: '⎙', label: t('payroll.payslip'), tone: 'amber' as const, onSelect: () => void openPayslip(payslipPath(row.item_id as number)) }] : []),
  ]

  const columns: Column<PayrollRow>[] = [
    {
      key: 'staff',
      header: t('attendance.columns.staff'),
      cell: (row) => (
        <span className="flex flex-col">
          <span className="font-medium">{row.staff.name}</span>
          <span className="text-12 text-app-muted">
            {roleLabel(row.staff.role, locale)} · {row.staff.employee_code}
          </span>
        </span>
      ),
    },
    { key: 'full', header: t('attendance.columns.full'), align: 'right', cell: (row) => <span className="font-display text-green">{number(row.totals.full)}</span> },
    { key: 'late', header: t('attendance.columns.late'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.late + row.totals.late_early ? 'text-amber' : 'text-app-muted'}`}>{number(row.totals.late + row.totals.late_early)}</span> },
    { key: 'early', header: t('attendance.columns.early'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.early ? 'text-amber' : 'text-app-muted'}`}>{number(row.totals.early)}</span> },
    { key: 'leave', header: t('attendance.columns.leave'), align: 'right', cell: (row) => <span className="font-display text-app-muted">{number(row.totals.leave_paid + row.totals.leave_unpaid)}</span> },
    { key: 'absent', header: t('attendance.columns.absent'), align: 'right', cell: (row) => <span className={`font-display ${row.totals.absent_days ? 'text-red' : 'text-app-muted'}`}>{number(row.totals.absent_days)}</span> },
    { key: 'base', header: t('payroll.columns.base'), align: 'right', cell: (row) => (row.base === null ? <Badge tone="orange">{t('payroll.noSalary')}</Badge> : <span className="font-display text-app-muted">{bdt(row.base)}</span>) },
    {
      key: 'payable',
      header: t('payroll.columns.payable'),
      align: 'right',
      // The design's payable with its cut underneath; an adjustment joins the cut on that line.
      cell: (row) =>
        row.figures ? (
          <span className="flex flex-col items-end leading-1.2">
            <span className="font-display font-bold">{bdt(row.figures.payable)}</span>
            <span className="flex gap-1.5 text-11 whitespace-nowrap">
              {row.figures.deductions > 0 ? <span className="text-red">{t('payroll.cut', { amount: bdt(row.figures.deductions) })}</span> : null}
              {row.figures.adjustments !== 0 ? <span className={row.figures.adjustments < 0 ? 'text-red' : 'text-green'}>{signed(row.figures.adjustments)}</span> : null}
              {row.figures.deductions <= 0 && row.figures.adjustments === 0 ? <span className="text-green">{t('payroll.noCut')}</span> : null}
            </span>
          </span>
        ) : (
          <span className="text-app-muted">—</span>
        ),
    },
    ...(finalised
      ? [
          {
            key: 'paid',
            header: t('common.status'),
            cell: (row: PayrollRow) =>
              row.paid_at ? (
                <span className="flex flex-col items-start gap-0.5">
                  <Badge tone="green">{t('payroll.paid')}</Badge>
                  <span className="text-11 text-app-muted">
                    {date(row.paid_at)}
                    {row.method ? ` · ${t(`bookings.methods.${row.method}`)}` : ''}
                  </span>
                </span>
              ) : (
                <Badge tone="orange">{t('payroll.unpaid')}</Badge>
              ),
          },
        ]
      : []),
  ]

  return (
    <>
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 flex-col gap-1">
            <span className="flex flex-wrap items-center gap-2">
              <h2 className="m-0 text-16 font-semibold" data-testid="payroll-month">
                {t('payroll.monthTitle', { month: monthLabel(sheet.month) })}
              </h2>
              {finalised ? <Badge tone="green">{t('payroll.finalised')}</Badge> : <Badge tone="orange">{t('payroll.draft')}</Badge>}
            </span>
            <span className="text-13 leading-1.55 text-app-muted">
              {finalised ? t('payroll.finalisedNote', { date: sheet.finalised_at ? date(sheet.finalised_at) : '—', name: sheet.finalised_by ?? '—' }) : sheet.month_over ? t('payroll.draftNote') : t('payroll.notOverNote')}
            </span>
            <span className="text-12 text-app-muted">
              {t('payroll.rulesNote', { days: number(sheet.rules.working_days_per_month), percent: number(sheet.rules.late_early_pay_percent) })}
              {seeDays ? (
                <>
                  {' · '}
                  <Link to={`/attendance?month=${sheet.month}`} className="font-semibold text-blue">
                    {t('payroll.attendanceLink')}
                  </Link>
                </>
              ) : null}
            </span>
          </div>
          <div className="flex flex-wrap gap-2">
            {sheet.actions.finalise ? (
              <button type="button" className={buttonClass('cta')} onClick={() => setOpen({ kind: 'finalise' })}>
                {t('payroll.finalise', { month: monthLabel(sheet.month) })}
              </button>
            ) : null}
            {sheet.actions.reopen ? (
              <button type="button" className={buttonClass('outline')} onClick={() => setOpen({ kind: 'reopen' })}>
                {t('payroll.reopen')}
              </button>
            ) : null}
          </div>
        </div>
      </Card>

      {!finalised && sheet.totals.missing_salary > 0 ? (
        <p role="note" className="m-0 rounded-12 border border-orange-line bg-orange-tint px-3.5 py-3 text-13 leading-1.6 text-orange-ink" data-testid="payroll-missing-salary">
          {t('payroll.missingSalary', { count: sheet.totals.missing_salary, n: number(sheet.totals.missing_salary), names: sheet.rows.filter((row) => row.base === null).map((row) => row.staff.name).join(', ') })}
        </p>
      ) : null}

      <div className="grid-auto-fit-200 grid gap-3" data-testid="payroll-kpis">
        {[
          [t('payroll.kpis.payable'), bdt(sheet.totals.payable), t('payroll.kpis.payableNote', { count: onPayroll.length, n: number(onPayroll.length) })],
          [t('payroll.kpis.base'), bdt(sheet.totals.base), t('payroll.kpis.baseNote')],
          [t('payroll.kpis.deductions'), bdt(sheet.totals.deductions), t('payroll.kpis.deductionsNote')],
          finalised
            ? [t('payroll.kpis.paid'), `${number(sheet.totals.paid)} / ${number(onPayroll.length)}`, t('payroll.kpis.paidNote')]
            : [t('payroll.kpis.adjustments'), sheet.totals.adjustments === 0 ? bdt(0) : signed(sheet.totals.adjustments), t('payroll.kpis.adjustmentsNote')],
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
          <h2 className="m-0 text-15 font-semibold">{t('payroll.tableTitle')}</h2>
          <span className="text-12 text-app-muted">{t('payroll.formula')}</span>
        </div>
        {sheet.rows.length === 0 ? (
          <EmptyState title={finalised ? t('payroll.noItems') : t('attendance.noStaff')} />
        ) : (
          <DataTable label={t('payroll.monthTitle', { month: monthLabel(sheet.month) })} testId="payroll-table" columns={columns} rows={sheet.rows} rowKey={(row) => row.staff.id} rowLabel={(row) => row.staff.name} actions={actionsFor} />
        )}
      </Card>

      <Card>
        <CardTitle title="Adjustments" aside={<span className="text-12 text-app-muted">{t('payroll.adjustmentsNote')}</span>} />
        {adjustments.length === 0 ? (
          <p className="m-0 text-13 text-app-muted">{t('payroll.noAdjustments')}</p>
        ) : (
          <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="payroll-adjustments">
            {adjustments.map(({ row, adjustment }) => (
              <li key={adjustment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-10 bg-app-surface-2 px-3 py-2 text-13">
                <span className="flex min-w-0 flex-col gap-0.5">
                  <span className="font-medium">{row.staff.name}</span>
                  <span className="text-12 text-app-muted">
                    {adjustment.reason}
                    {adjustment.by ? ` · ${adjustment.by}` : ''}
                  </span>
                </span>
                <span className="flex items-center gap-3">
                  <span className={`font-display font-semibold ${adjustment.amount < 0 ? 'text-red' : 'text-green'}`}>{signed(adjustment.amount)}</span>
                  {sheet.actions.adjust && !own(row) ? (
                    <button
                      type="button"
                      className="cursor-pointer text-12 font-semibold text-red"
                      disabled={removeAdjustment.isPending}
                      aria-label={t('payroll.removeAdjustmentNamed', { name: row.staff.name, reason: adjustment.reason })}
                      onClick={async () => {
                        if (await confirm(t('payroll.removeAdjustmentConfirm', { name: row.staff.name, amount: signed(adjustment.amount) }))) {
                          removeAdjustment.mutate(adjustment.id, { onSuccess: () => toast(t('payroll.adjustmentRemoved')) })
                        }
                      }}
                    >
                      {t('common.remove')}
                    </button>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>
        )}
        {removeAdjustment.error ? <ErrorNotice error={removeAdjustment.error} /> : null}
      </Card>

      {open?.kind === 'adjust' ? <AdjustDialog month={sheet.month} row={open.row} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'pay' ? <PayDialog sheet={sheet} row={open.row} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'salary' ? <SalaryDialog person={open.row.staff} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'finalise' ? <FinaliseDialog sheet={sheet} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'reopen' ? <ReopenDialog month={sheet.month} onClose={() => setOpen(null)} /> : null}
      {element}
    </>
  )
}
