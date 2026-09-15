import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useAuth, useStaff } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Chips, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { payrollActions, usePayrollChange, useSalaries, type PayrollRow, type PayrollSheet } from './api'

const validationOnly = (error: unknown) => error instanceof ApiError && error.status === 422

/** An allowance (+) or a recovery (−) on one person's pay for a draft month, with the reason the payslip will show. */
export function AdjustDialog({ month, row, onClose }: { month: string; row: PayrollRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, month: monthLabel } = useFormat()
  const toast = useToast()
  const [kind, setKind] = useState<'allowance' | 'recovery'>('allowance')
  const [amount, setAmount] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const adjust = usePayrollChange(payrollActions.adjust)
  const fieldError = (name: string) => (adjust.error instanceof ApiError ? adjust.error.field(name) : undefined)
  const signedAmount = (amount ?? 0) * (kind === 'recovery' ? -1 : 1)
  const after = row.base !== null && row.figures ? Math.max(0, Math.round(row.base - row.figures.deductions + row.figures.adjustments + signedAmount)) : null

  return (
    <Dialog open onClose={onClose} title={t('payroll.adjustTitle', { name: row.staff.name, month: monthLabel(month) })}>
      <Chips label={t('payroll.adjustKind')} value={kind} onChange={setKind} options={(['allowance', 'recovery'] as const).map((value) => ({ value, label: t(`payroll.adjustKinds.${value}`) }))} />
      <NumberInput label={t('payroll.amount')} value={amount} onChange={setAmount} preview={(value) => bdt(value)} error={fieldError('amount')} />
      <TextArea label={t('payroll.reason')} value={reason} onChange={setReason} rows={2} maxLength={300} hint={t('payroll.adjustReasonHint')} error={fieldError('reason')} />
      {after !== null && (amount ?? 0) > 0 ? <p className="m-0 text-13 text-app-muted">{t('payroll.payableAfter', { amount: bdt(after) })}</p> : null}
      {adjust.error && !validationOnly(adjust.error) ? <ErrorNotice error={adjust.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={(amount ?? 0) <= 0 || reason.trim().length < 3 || adjust.isPending}
          onClick={() =>
            adjust.mutate(
              { month, staff_id: row.staff.id, amount: signedAmount, reason: reason.trim() },
              {
                onSuccess: () => {
                  toast(t('payroll.adjusted'))
                  onClose()
                },
              },
            )
          }
        >
          {t('payroll.addAdjustment')}
        </button>
      </div>
    </Dialog>
  )
}

/** Paying one person's finalised month: a cash-out under Salaries, from the account the method names, with its receipt. */
export function PayDialog({ sheet, row, onClose }: { sheet: PayrollSheet; row: PayrollRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, month: monthLabel } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState({ method: sheet.methods.includes('cash') ? 'cash' : (sheet.methods[0] ?? ''), reference: '', occurred_on: todayInDhaka() })
  const [evidence, setEvidence] = useState<File | null>(null)
  const pay = usePayrollChange(payrollActions.pay)
  const fieldError = (name: string) => (pay.error instanceof ApiError ? pay.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('payroll.payTitle', { name: row.staff.name, month: monthLabel(sheet.month) })}>
      <p className="m-0 text-14">
        {t('payroll.payAmount')} <strong className="font-display text-16">{bdt(row.figures?.payable ?? 0)}</strong>
      </p>
      <p className="m-0 text-12.5 leading-1.55 text-app-muted">{t('payroll.payNote')}</p>
      <Pair>
        <SelectInput label={t('bookings.method')} value={form.method} onChange={(method) => setForm({ ...form, method })} options={sheet.methods.map((value) => ({ value, label: t(`bookings.methods.${value}`) }))} error={fieldError('method')} />
        <TextInput label={t('payroll.paidOnLabel')} type="date" max={todayInDhaka()} value={form.occurred_on} onChange={(occurred_on) => setForm({ ...form, occurred_on })} error={fieldError('occurred_on')} />
      </Pair>
      <TextInput label={t('bookings.paymentReference')} value={form.reference} onChange={(reference) => setForm({ ...form, reference })} hint={t('bookings.paymentReferenceHint')} error={fieldError('reference')} maxLength={120} />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />
      {pay.error && !validationOnly(pay.error) ? <ErrorNotice error={pay.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('success')}
          disabled={!evidence || !evidenceReady(evidence) || !form.method || pay.isPending}
          onClick={() => {
            if (row.item_id === null || evidence === null) return
            const body = new FormData()
            body.append('method', form.method)
            if (form.reference.trim()) body.append('reference', form.reference.trim())
            if (form.occurred_on) body.append('occurred_on', form.occurred_on)
            body.append('evidence', evidence)
            pay.mutate(
              { id: row.item_id, form: body },
              {
                onSuccess: () => {
                  toast(t('payroll.paidToast', { name: row.staff.name }))
                  onClose()
                },
              },
            )
          }}
        >
          {pay.isPending ? t('common.saving') : t('payroll.markPaid')}
        </button>
      </div>
    </Dialog>
  )
}

/** Freezing a month: what it pays, and who is left off for want of a base salary. */
export function FinaliseDialog({ sheet, onClose }: { sheet: PayrollSheet; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, number, month: monthLabel } = useFormat()
  const toast = useToast()
  const finalise = usePayrollChange(payrollActions.finalise)
  const onPayroll = sheet.rows.filter((row) => row.base !== null)
  const leftOff = sheet.rows.filter((row) => row.base === null)

  return (
    <Dialog open onClose={onClose} title={t('payroll.finaliseTitle', { month: monthLabel(sheet.month) })}>
      <p className="m-0 text-14 leading-1.6">{t('payroll.finaliseSummary', { count: onPayroll.length, n: number(onPayroll.length), amount: bdt(sheet.totals.payable) })}</p>
      <ul className="m-0 flex list-disc flex-col gap-1 pl-5 text-13 leading-1.55">
        <li>{t('payroll.finaliseFreezes')}</li>
        <li>{t('payroll.finaliseEmails')}</li>
        {leftOff.length > 0 ? <li className="text-orange-ink">{t('payroll.finaliseLeavesOff', { count: leftOff.length, n: number(leftOff.length), names: leftOff.map((row) => row.staff.name).join(', ') })}</li> : null}
      </ul>
      {finalise.error ? <ErrorNotice error={finalise.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('cta')}
          disabled={onPayroll.length === 0 || finalise.isPending}
          onClick={() =>
            finalise.mutate(sheet.month, {
              onSuccess: () => {
                toast(t('payroll.finalisedToast'))
                onClose()
              },
            })
          }
        >
          {finalise.isPending ? t('common.working') : t('payroll.finaliseConfirm')}
        </button>
      </div>
    </Dialog>
  )
}

/** Back to a draft, before anyone is paid: the super admin's, with a reason for the audit log. */
export function ReopenDialog({ month, onClose }: { month: string; onClose: () => void }) {
  const { t } = useTranslation()
  const { month: monthLabel } = useFormat()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reopen = usePayrollChange(payrollActions.reopen)

  return (
    <Dialog open onClose={onClose} title={t('payroll.reopenTitle', { month: monthLabel(month) })}>
      <p className="m-0 text-13 leading-1.55 text-app-muted">{t('payroll.reopenNote')}</p>
      <TextArea label={t('payroll.reason')} value={reason} onChange={setReason} rows={2} maxLength={300} error={reopen.error instanceof ApiError ? reopen.error.field('reason') : undefined} />
      {reopen.error && !validationOnly(reopen.error) ? <ErrorNotice error={reopen.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={reason.trim().length < 3 || reopen.isPending}
          onClick={() =>
            reopen.mutate(
              { month, reason: reason.trim() },
              {
                onSuccess: () => {
                  toast(t('payroll.reopenedToast'))
                  onClose()
                },
              },
            )
          }
        >
          {t('payroll.reopen')}
        </button>
      </div>
    </Dialog>
  )
}

export function SalaryDialog({ person, onClose }: { person: { id: number; name: string }; onClose: () => void }) {
  const { t } = useTranslation()
  return (
    <Dialog open onClose={onClose} title={t('payroll.salaryTitle', { name: person.name })}>
      <SalaryHistory staffId={person.id} />
    </Dialog>
  )
}

/**
 * A person's base salary by the month it takes effect, newest first. A raise or a correction is a new row with its
 * reason; nobody sets their own, and a month already finalised can't be reached back into.
 */
export function SalaryHistory({ staffId }: { staffId: number }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const me = useStaff()
  const { bdt, date, month: monthLabel } = useFormat()
  const toast = useToast()
  const salaries = useSalaries(staffId)
  const set = usePayrollChange(payrollActions.setSalary)
  const [form, setForm] = useState({ month: todayInDhaka().slice(0, 7), amount: null as number | null, reason: '' })
  const fieldError = (name: string) => (set.error instanceof ApiError ? set.error.field(name) : undefined)
  const canSet = can('payroll.manage') && (staffId !== me.id || me.is_super_admin)

  return (
    <div className="flex flex-col gap-3">
      {salaries.isPending ? (
        <Loading />
      ) : salaries.isError ? (
        <ErrorNotice error={salaries.error} />
      ) : salaries.data.data.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('payroll.noSalaryHistory')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="salary-history">
          {salaries.data.data.map((change, index) => (
            <li key={change.id} className={`flex flex-wrap items-start justify-between gap-2 rounded-10 px-3 py-2 text-13 ${index === 0 ? 'bg-blue-tint' : 'bg-app-surface-2'}`}>
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="font-medium">{t('payroll.fromMonthShort', { month: monthLabel(change.effective_month) })}</span>
                <span className="text-12 text-app-muted">
                  {change.reason}
                  {change.by ? ` · ${change.by}` : ''}
                  {change.created_at ? ` · ${date(change.created_at)}` : ''}
                </span>
              </span>
              <span className="font-display font-semibold">{bdt(change.amount)}</span>
            </li>
          ))}
        </ul>
      )}
      {canSet ? (
        <form
          className="flex flex-col gap-3 border-t border-app-line pt-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (form.amount === null) return
            set.mutate(
              { staffId, month: form.month, amount: form.amount, reason: form.reason.trim() },
              {
                onSuccess: () => {
                  toast(t('payroll.salarySaved'))
                  setForm({ ...form, amount: null, reason: '' })
                },
              },
            )
          }}
        >
          <Pair>
            <TextInput label={t('payroll.fromMonth')} type="month" value={form.month} onChange={(month) => setForm({ ...form, month })} error={fieldError('month')} />
            <NumberInput label={t('payroll.baseSalary')} value={form.amount} onChange={(amount) => setForm({ ...form, amount })} preview={(value) => bdt(value)} error={fieldError('amount')} />
          </Pair>
          <TextInput label={t('payroll.reason')} value={form.reason} onChange={(reason) => setForm({ ...form, reason })} hint={t('payroll.salaryReasonHint')} error={fieldError('reason')} maxLength={300} />
          {set.error && !validationOnly(set.error) ? <ErrorNotice error={set.error} /> : null}
          <button type="submit" className={buttonClass('primary', 'md', 'self-end')} disabled={!/^\d{4}-\d{2}$/.test(form.month) || (form.amount ?? 0) < 1 || form.reason.trim().length < 3 || set.isPending}>
            {set.isPending ? t('common.saving') : t('payroll.saveSalary')}
          </button>
        </form>
      ) : can('payroll.manage') ? (
        <p className="m-0 text-12 text-app-muted">{t('payroll.ownSalaryNote')}</p>
      ) : null}
    </div>
  )
}
