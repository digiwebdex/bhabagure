import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, TextArea } from '../../components/ui/fields'
import { Card, CardTitle, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { bonusActions, useBonusChange, useBonusLedger, type BonusEntry } from './api'
import { WithdrawalStatusBadge } from './WithdrawalsCard'

/**
 * A person's bonus account on their staff record (docs/phase-7-hr-attendance-bonus-wallet.md §7): balance and what is
 * held by open withdrawals, the ledger, a credit by hand and reversing an entry — each with its reason.
 */
export function BonusLedgerCard({ staffId, name }: { staffId: number; name: string }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const ledger = useBonusLedger(staffId)
  const [crediting, setCrediting] = useState(false)
  const [reversing, setReversing] = useState<BonusEntry | null>(null)

  return (
    <Card>
      <CardTitle
        bn="বোনাস অ্যাকাউন্ট"
        en="Bonus account"
        aside={
          ledger.data?.data.actions.credit ? (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setCrediting(true)}>
              {t('bonus.credit')}
            </button>
          ) : null
        }
      />
      {ledger.isPending ? (
        <Loading />
      ) : ledger.isError ? (
        <ErrorNotice error={ledger.error} />
      ) : (
        <>
          <div className="flex flex-wrap gap-2" data-testid="bonus-balance">
            {[
              [t('bonus.balance'), ledger.data.data.balance],
              [t('bonus.held'), ledger.data.data.held],
              [t('bonus.available'), ledger.data.data.available],
            ].map(([label, value]) => (
              <span key={label} className="rounded-10 bg-app-surface-2 px-3 py-1.5 text-13">
                <span className="text-app-muted">{label}</span> <span className="font-display font-semibold">{bdt(value)}</span>
              </span>
            ))}
          </div>
          <EntryList entries={ledger.data.data.entries} onReverse={ledger.data.data.actions.reverse ? setReversing : undefined} />
          {ledger.data.data.withdrawals.length > 0 ? (
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0 text-13">
              {ledger.data.data.withdrawals.slice(0, 5).map((withdrawal) => (
                <li key={withdrawal.id} className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-app-muted">{t('bonus.withdrawalLine', { date: withdrawal.requested_at ? date(withdrawal.requested_at) : '—' })}</span>
                  <span className="flex items-center gap-2">
                    <span className="font-display">{bdt(withdrawal.amount)}</span>
                    <WithdrawalStatusBadge status={withdrawal.status} />
                  </span>
                </li>
              ))}
            </ul>
          ) : null}
        </>
      )}
      {crediting ? <CreditDialog staffId={staffId} name={name} onClose={() => setCrediting(false)} /> : null}
      {reversing ? <ReverseDialog entry={reversing} onClose={() => setReversing(null)} /> : null}
    </Card>
  )
}

/** Ledger rows, newest first: what moved the balance, why, and who. */
export function EntryList({ entries, onReverse }: { entries: BonusEntry[]; onReverse?: (entry: BonusEntry) => void }) {
  const { t } = useTranslation()
  const { bdt, date, month, number, percent } = useFormat()
  if (entries.length === 0) return <p className="m-0 text-13 text-app-muted">{t('bonus.noEntries')}</p>

  // What a system entry was made from: its rule, or why it was reversed. Entries made by hand carry their reason instead.
  const describe = (entry: BonusEntry): string | null => {
    const rule = entry.rule
    if (!rule) return null
    if ('cause' in rule) return rule.cause === 'reassigned' && rule.to ? t('bonus.rule.reassignedTo', { name: rule.to }) : t(`bonus.rule.${rule.cause}`)
    if (rule.type === 'volume') return t('bonus.rule.volume', { rate: percent(rule.rate), base: bdt(rule.base), count: rule.bookings, n: number(rule.bookings), month: month(rule.month) })
    return t('bonus.rule.sale', { rate: percent(rule.rate), base: bdt(rule.base) })
  }

  return (
    <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="bonus-entries">
      {entries.map((entry) => (
        <li key={entry.id} className={`flex flex-wrap items-start justify-between gap-2 rounded-10 bg-app-surface-2 px-3 py-2 text-13 ${entry.reversed ? 'opacity-60' : ''}`}>
          <span className="flex min-w-0 flex-col gap-0.5">
            <span className="font-medium">
              {t(`bonus.kinds.${entry.kind}`)}
              {entry.booking ? ` · ${entry.booking.reference}` : ''}
              {entry.reversed ? ` · ${t('bonus.reversed')}` : ''}
            </span>
            <span className="text-12 text-app-muted">
              {entry.reason ?? describe(entry) ?? (entry.withdrawal_id ? t('bonus.withdrawalNumber', { id: entry.withdrawal_id }) : '—')}
              {entry.by ? ` · ${entry.by}` : entry.rule ? ` · ${t('bonus.automatic')}` : ''}
              {entry.created_at ? ` · ${date(entry.created_at)}` : ''}
            </span>
          </span>
          <span className="flex items-center gap-3">
            <span className={`font-display font-semibold ${entry.direction === 'credit' ? 'text-green' : 'text-red'}`}>
              {entry.direction === 'credit' ? '+' : '−'} {bdt(entry.amount)}
            </span>
            {onReverse && entry.reversible ? (
              <button type="button" className="cursor-pointer text-12 font-semibold text-red" onClick={() => onReverse(entry)} aria-label={t('bonus.reverseNamed', { reason: entry.reason ?? entry.kind })}>
                {t('bonus.reverse')}
              </button>
            ) : null}
          </span>
        </li>
      ))}
    </ul>
  )
}

function CreditDialog({ staffId, name, onClose }: { staffId: number; name: string; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [amount, setAmount] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const credit = useBonusChange(bonusActions.credit)
  const fieldError = (field: string) => (credit.error instanceof ApiError ? credit.error.field(field) : undefined)

  return (
    <Dialog open onClose={onClose} title={t('bonus.creditTitle', { name })}>
      <NumberInput label={t('payroll.amount')} value={amount} onChange={setAmount} preview={(value) => bdt(value)} error={fieldError('amount')} />
      <TextArea label={t('payroll.reason')} value={reason} onChange={setReason} rows={2} maxLength={300} hint={t('bonus.creditReasonHint')} error={fieldError('reason')} />
      {credit.error && !(credit.error instanceof ApiError && credit.error.status === 422) ? <ErrorNotice error={credit.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={(amount ?? 0) < 1 || reason.trim().length < 3 || credit.isPending}
          onClick={() =>
            credit.mutate(
              { staffId, amount: amount ?? 0, reason: reason.trim() },
              {
                onSuccess: () => {
                  toast(t('bonus.credited', { name }))
                  onClose()
                },
              },
            )
          }
        >
          {t('bonus.credit')}
        </button>
      </div>
    </Dialog>
  )
}

function ReverseDialog({ entry, onClose }: { entry: BonusEntry; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reverse = useBonusChange(bonusActions.reverse)

  return (
    <Dialog open onClose={onClose} title={t('bonus.reverseTitle', { amount: bdt(entry.amount) })}>
      <p className="m-0 text-13 leading-1.55 text-app-muted">{t('bonus.reverseNote')}</p>
      {entry.kind === 'commission' ? <p className="m-0 text-13 leading-1.55 font-semibold">{t('bonus.reverseCommissionNote')}</p> : null}
      <TextArea label={t('payroll.reason')} value={reason} onChange={setReason} rows={2} maxLength={300} error={reverse.error instanceof ApiError ? reverse.error.field('reason') : undefined} />
      {reverse.error && !(reverse.error instanceof ApiError && reverse.error.status === 422) ? <ErrorNotice error={reverse.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={reason.trim().length < 3 || reverse.isPending}
          onClick={() =>
            reverse.mutate(
              { id: entry.id, reason: reason.trim() },
              {
                onSuccess: () => {
                  toast(t('bonus.reversedToast'))
                  onClose()
                },
              },
            )
          }
        >
          {t('bonus.reverse')}
        </button>
      </div>
    </Dialog>
  )
}
