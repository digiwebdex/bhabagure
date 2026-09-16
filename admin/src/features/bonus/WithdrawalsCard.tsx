import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Chips, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { bonusActions, useBonusChange, useWithdrawals, WITHDRAWAL_FILTERS, type Withdrawal, type WithdrawalFilter, type WithdrawalStatus } from './api'

const FILTERS = WITHDRAWAL_FILTERS
const TONES: Record<WithdrawalStatus, 'orange' | 'blue' | 'green' | 'slate' | 'red'> = { pending: 'orange', approved: 'blue', paid: 'green', rejected: 'red', cancelled: 'slate' }

export function WithdrawalStatusBadge({ status }: { status: WithdrawalStatus }) {
  const { t } = useTranslation()
  return <Badge tone={TONES[status]}>{t(`bonus.status.${status}`)}</Badge>
}

/**
 * The design's "Bonus withdrawals" card (docs/phase-7-hr-attendance-bonus-wallet.md §7): pending requests to approve or
 * reject, approved ones to mark paid, and what was decided. The Staff badge counts the open ones.
 */
export function WithdrawalsCard({ initial = 'open' }: { initial?: WithdrawalFilter }) {
  const { t } = useTranslation()
  const { bdt, date, number } = useFormat()
  const [status, setStatus] = useState<WithdrawalFilter>(initial)
  const [page, setPage] = useState(1)
  const list = useWithdrawals(status, page)
  const [open, setOpen] = useState<{ kind: 'approve' | 'reject' | 'pay'; withdrawal: Withdrawal } | null>(null)

  return (
    <Card>
      <CardTitle title="Bonus withdrawals" />
      <Chips
        label={t('common.status')}
        value={status}
        onChange={(value) => {
          setStatus(value)
          setPage(1)
        }}
        options={FILTERS.map((value) => ({ value, label: list.data ? `${t(`bonus.filters.${value}`)} · ${number(list.data.meta.status_counts[value])}` : t(`bonus.filters.${value}`) }))}
      />
      {list.isPending ? (
        <Loading />
      ) : list.isError ? (
        <ErrorNotice error={list.error} />
      ) : list.data.data.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{status === 'open' ? t('bonus.noneOpen') : t('bonus.none')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="bonus-withdrawals">
          {list.data.data.map((withdrawal) => (
            <li key={withdrawal.id} className="flex flex-col gap-2 rounded-12 bg-app-surface-2 px-3.5 py-3">
              <span className="flex flex-wrap items-start justify-between gap-2 text-14">
                <span className="font-medium">{withdrawal.staff.name}</span>
                <span className="font-display font-bold">{bdt(withdrawal.amount)}</span>
              </span>
              <span className="flex flex-wrap items-center gap-2 text-12 text-app-muted">
                <WithdrawalStatusBadge status={withdrawal.status} />
                {withdrawal.requested_at ? t('bonus.requestedOn', { date: date(withdrawal.requested_at) }) : null}
                {withdrawal.note ? ` · ${withdrawal.note}` : null}
              </span>
              {withdrawal.decision_note ? <span className="text-12">{t('bonus.decisionNote', { name: withdrawal.decided_by ?? '—', note: withdrawal.decision_note })}</span> : null}
              {withdrawal.status === 'paid' ? (
                <span className="text-12 font-semibold text-green">
                  {t('bonus.paidLine', { date: withdrawal.paid_at ? date(withdrawal.paid_at) : '—', name: withdrawal.paid_by ?? '—' })}
                  {withdrawal.method ? ` · ${t(`bookings.methods.${withdrawal.method}`)}` : ''}
                  {withdrawal.reference ? ` · ${withdrawal.reference}` : ''}
                </span>
              ) : null}
              {withdrawal.actions?.approve || withdrawal.actions?.reject ? (
                <span className="flex gap-2">
                  {withdrawal.actions.approve ? (
                    <button type="button" className={buttonClass('success', 'sm', 'flex-1')} onClick={() => setOpen({ kind: 'approve', withdrawal })} aria-label={t('bonus.approveNamed', { name: withdrawal.staff.name })}>
                      {t('bonus.approve')}
                    </button>
                  ) : null}
                  {withdrawal.actions.pay ? (
                    <button type="button" className={buttonClass('primary', 'sm', 'flex-1')} onClick={() => setOpen({ kind: 'pay', withdrawal })} aria-label={t('bonus.payNamed', { name: withdrawal.staff.name })}>
                      {t('bonus.markPaid')}
                    </button>
                  ) : null}
                  {withdrawal.actions.reject ? (
                    <button type="button" className={buttonClass('outline', 'sm', 'flex-1')} onClick={() => setOpen({ kind: 'reject', withdrawal })} aria-label={t('bonus.rejectNamed', { name: withdrawal.staff.name })}>
                      {t('bonus.reject')}
                    </button>
                  ) : null}
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {list.data && list.data.meta.last_page > 1 ? (
        <div className="flex items-center justify-between gap-3 text-13">
          <button type="button" className={buttonClass('outline', 'sm')} disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('common.previous')}
          </button>
          <span className="text-app-muted">{t('common.pageOf', { page: number(page), last: number(list.data.meta.last_page) })}</span>
          <button type="button" className={buttonClass('outline', 'sm')} disabled={page >= list.data.meta.last_page} onClick={() => setPage(page + 1)}>
            {t('common.next')}
          </button>
        </div>
      ) : null}
      {open && open.kind !== 'pay' ? <DecideDialog kind={open.kind} withdrawal={open.withdrawal} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'pay' && list.data ? <PayoutDialog withdrawal={open.withdrawal} methods={list.data.meta.methods} onClose={() => setOpen(null)} /> : null}
    </Card>
  )
}

function DecideDialog({ kind, withdrawal, onClose }: { kind: 'approve' | 'reject'; withdrawal: Withdrawal; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [note, setNote] = useState('')
  const decide = useBonusChange(kind === 'approve' ? bonusActions.approve : bonusActions.reject)
  const needsNote = kind === 'reject'

  return (
    <Dialog open onClose={onClose} title={t(`bonus.titles.${kind}`, { name: withdrawal.staff.name, amount: bdt(withdrawal.amount) })}>
      {withdrawal.note ? <p className="m-0 text-13 text-app-muted">{t('bonus.theirNote', { note: withdrawal.note })}</p> : null}
      <TextArea label={needsNote ? t('bonus.rejectWhy') : t('bonus.noteOptional')} value={note} onChange={setNote} rows={2} maxLength={300} error={decide.error instanceof ApiError ? decide.error.field('note') : undefined} />
      {decide.error && !(decide.error instanceof ApiError && decide.error.status === 422) ? <ErrorNotice error={decide.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass(kind === 'approve' ? 'success' : 'primary')}
          disabled={(needsNote && note.trim().length < 3) || decide.isPending}
          onClick={() =>
            decide.mutate(
              { id: withdrawal.id, note: note.trim() },
              {
                onSuccess: () => {
                  toast(t(`bonus.done.${kind}`, { name: withdrawal.staff.name }))
                  onClose()
                },
              },
            )
          }
        >
          {t(`bonus.${kind}`)}
        </button>
      </div>
    </Dialog>
  )
}

/** Paying an approved withdrawal: a cash-out under Staff bonuses, from the account the method names, with its receipt. */
function PayoutDialog({ withdrawal, methods, onClose }: { withdrawal: Withdrawal; methods: string[]; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState({ method: methods.includes('cash') ? 'cash' : (methods[0] ?? ''), reference: '', occurred_on: todayInDhaka() })
  const [evidence, setEvidence] = useState<File | null>(null)
  const pay = useBonusChange(bonusActions.pay)
  const fieldError = (name: string) => (pay.error instanceof ApiError ? pay.error.field(name) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('bonus.titles.pay', { name: withdrawal.staff.name, amount: bdt(withdrawal.amount) })}>
      <p className="m-0 text-12.5 leading-1.55 text-app-muted">{t('bonus.payNote')}</p>
      <Pair>
        <SelectInput label={t('bookings.method')} value={form.method} onChange={(method) => setForm({ ...form, method })} options={methods.map((value) => ({ value, label: t(`bookings.methods.${value}`) }))} error={fieldError('method')} />
        <TextInput label={t('payroll.paidOnLabel')} type="date" max={todayInDhaka()} value={form.occurred_on} onChange={(occurred_on) => setForm({ ...form, occurred_on })} error={fieldError('occurred_on')} />
      </Pair>
      <TextInput label={t('bookings.paymentReference')} value={form.reference} onChange={(reference) => setForm({ ...form, reference })} hint={t('bookings.paymentReferenceHint')} error={fieldError('reference')} maxLength={120} />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />
      {pay.error && !(pay.error instanceof ApiError && pay.error.status === 422) ? <ErrorNotice error={pay.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('success')}
          disabled={!evidence || !evidenceReady(evidence) || !form.method || pay.isPending}
          onClick={() => {
            if (evidence === null) return
            const body = new FormData()
            body.append('method', form.method)
            if (form.reference.trim()) body.append('reference', form.reference.trim())
            if (form.occurred_on) body.append('occurred_on', form.occurred_on)
            body.append('evidence', evidence)
            pay.mutate(
              { id: withdrawal.id, form: body },
              {
                onSuccess: () => {
                  toast(t('bonus.done.pay', { name: withdrawal.staff.name }))
                  onClose()
                },
              },
            )
          }}
        >
          {pay.isPending ? t('common.saving') : t('bonus.markPaid')}
        </button>
      </div>
    </Dialog>
  )
}
