import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useConfirm, useToast } from '../../components/ui/feedback'
import { NumberInput, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { bonusActions, useBonusChange, useMyCommission, type MyCommission } from './api'
import { EntryList } from './BonusLedgerCard'
import { WithdrawalStatusBadge } from './WithdrawalsCard'

/**
 * My commission (docs/phase-5-admin-core.md §4.8, docs/phase-7-hr-attendance-bonus-wallet.md §7): the signed-in staff
 * member's own sales, bonus account and withdrawal requests — nobody else's, and never the company's figures.
 */
export function MyCommissionPage() {
  const { t } = useTranslation()
  const mine = useMyCommission()

  return (
    <>
      <PageHeader title={t('myCommission.title')} subtitle={t('myCommission.subtitle')} />
      {mine.isPending ? <Loading /> : mine.isError ? <ErrorNotice error={mine.error} /> : <Commission data={mine.data.data} />}
    </>
  )
}

function Commission({ data }: { data: MyCommission }) {
  const { t } = useTranslation()
  const { bdt, date, number, percent } = useFormat()
  const toast = useToast()
  const { confirm, element } = useConfirm()
  const cancel = useBonusChange(bonusActions.cancel)
  const { rules, volume } = data
  const left = Math.max(0, rules.volume_threshold - volume.count)

  return (
    <>
      <div className="grid-auto-fit-200 grid gap-3" data-testid="my-commission-kpis">
        {[
          [t('myCommission.available'), bdt(data.available), t('myCommission.availableNote', { balance: bdt(data.balance), held: bdt(data.held) })],
          [t('myCommission.salesThisMonth'), bdt(data.sales.this_month.total), t('myCommission.salesCount', { count: data.sales.this_month.count, n: number(data.sales.this_month.count) })],
          [t('myCommission.salesLastMonth'), bdt(data.sales.last_month.total), t('myCommission.salesCount', { count: data.sales.last_month.count, n: number(data.sales.last_month.count) })],
          [t('myCommission.commissionThisMonth'), bdt(data.commission.this_month), t('myCommission.commissionTotal', { amount: bdt(data.commission.total) })],
          ...(rules.earns
            ? [
                [
                  t('myCommission.volume'),
                  t('myCommission.volumeValue', { n: number(volume.count), threshold: number(rules.volume_threshold) }),
                  left > 0
                    ? t('myCommission.volumeLeft', { count: left, n: number(left), rate: percent(rules.volume_rate) })
                    : t('myCommission.volumeReached', { rate: percent(rules.volume_rate), base: bdt(volume.base), amount: bdt(Math.round((volume.base * rules.volume_rate) / 100)) }),
                ],
              ]
            : []),
        ].map(([label, value, note]) => (
          <Card key={label}>
            <span className="text-12 text-app-muted">{label}</span>
            <span className="font-display text-22 font-bold">{value}</span>
            <span className="text-12 text-app-muted">{note}</span>
          </Card>
        ))}
      </div>
      <p className="m-0 max-w-[80ch] text-13 text-app-muted" data-testid="commission-rules">
        {rules.earns
          ? t('myCommission.rules', {
              tour: percent(rules.rates.tour),
              air: percent(rules.rates.air),
              hotel: percent(rules.rates.hotel),
              threshold: number(rules.volume_threshold),
              volume: percent(rules.volume_rate),
            })
          : t('myCommission.noCommission')}
      </p>

      <div className="grid items-start gap-4.5 xl:grid-cols-[minmax(280px,1fr)_minmax(0,1.4fr)]">
        <div className="flex flex-col gap-4.5">
          <WithdrawalForm data={data} />
          <Card>
            <CardTitle title="My withdrawals" />
            {data.withdrawals.length === 0 ? (
              <p className="m-0 text-13 text-app-muted">{t('myCommission.noWithdrawals')}</p>
            ) : (
              <ul className="m-0 flex list-none flex-col gap-2 p-0 text-13" data-testid="my-withdrawals">
                {data.withdrawals.map((withdrawal) => (
                  <li key={withdrawal.id} className="flex flex-col gap-1 rounded-10 bg-app-surface-2 px-3 py-2">
                    <span className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-display font-semibold">{bdt(withdrawal.amount)}</span>
                      <WithdrawalStatusBadge status={withdrawal.status} />
                    </span>
                    <span className="text-12 text-app-muted">
                      {withdrawal.requested_at ? t('bonus.requestedOn', { date: date(withdrawal.requested_at) }) : null}
                      {withdrawal.note ? ` · ${withdrawal.note}` : ''}
                    </span>
                    {withdrawal.decision_note ? <span className="text-12">{t('bonus.decisionNote', { name: withdrawal.decided_by ?? '—', note: withdrawal.decision_note })}</span> : null}
                    {withdrawal.status === 'paid' && withdrawal.paid_at ? <span className="text-12 font-semibold text-green">{t('myCommission.paidOn', { date: date(withdrawal.paid_at) })}</span> : null}
                    {withdrawal.cancellable ? (
                      <button
                        type="button"
                        className="cursor-pointer self-start text-12 font-semibold text-red"
                        disabled={cancel.isPending}
                        onClick={async () => {
                          if (await confirm(t('myCommission.cancelConfirm', { amount: bdt(withdrawal.amount) }))) cancel.mutate(withdrawal.id, { onSuccess: () => toast(t('myCommission.cancelled')) })
                        }}
                      >
                        {t('myCommission.cancel')}
                      </button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
            {cancel.error ? <ErrorNotice error={cancel.error} /> : null}
          </Card>
        </div>
        <Card>
          <CardTitle title="Bonus ledger" />
          <EntryList entries={data.entries} />
        </Card>
      </div>
      {element}
    </>
  )
}

/** The withdrawal form: at least the minimum, at most what is available (balance less open requests). */
function WithdrawalForm({ data }: { data: MyCommission }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [amount, setAmount] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const request = useBonusChange(bonusActions.request)
  const fieldError = (field: string) => (request.error instanceof ApiError ? request.error.field(field) : undefined)
  const tooSmall = amount !== null && amount < data.min_withdrawal
  const tooBig = amount !== null && amount > data.available
  const canAsk = data.available >= data.min_withdrawal

  return (
    <Card>
      <CardTitle title="Request a withdrawal" />
      <form
        className="flex flex-col gap-3"
        data-testid="withdrawal-form"
        onSubmit={(event) => {
          event.preventDefault()
          if (amount === null || tooSmall || tooBig) return
          request.mutate(
            { amount, note: note.trim() },
            {
              onSuccess: () => {
                toast(t('myCommission.requested'))
                setAmount(null)
                setNote('')
              },
            },
          )
        }}
      >
        <p className="m-0 text-13 text-app-muted">{canAsk ? t('myCommission.formNote', { min: bdt(data.min_withdrawal), available: bdt(data.available) }) : t('myCommission.notEnough', { min: bdt(data.min_withdrawal) })}</p>
        <NumberInput
          label={t('payroll.amount')}
          value={amount}
          onChange={setAmount}
          disabled={!canAsk}
          error={fieldError('amount') ?? (tooSmall ? t('myCommission.belowMin', { min: bdt(data.min_withdrawal) }) : tooBig ? t('myCommission.overAvailable', { available: bdt(data.available) }) : undefined)}
          preview={(value) => bdt(value)}
        />
        <TextInput label={t('myCommission.note')} value={note} onChange={setNote} disabled={!canAsk} maxLength={300} error={fieldError('note')} />
        {request.error && !(request.error instanceof ApiError && request.error.status === 422) ? <ErrorNotice error={request.error} /> : null}
        <button type="submit" className={buttonClass('cta', 'md', 'w-full')} disabled={!canAsk || amount === null || tooSmall || tooBig || request.isPending}>
          {request.isPending ? t('common.working') : t('myCommission.send')}
        </button>
      </form>
    </Card>
  )
}
