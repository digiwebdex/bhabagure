import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, PageHeader } from '../../components/ui/layout'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentsMutation, usePaymentsSummary, useReviewQueue, type Balance, type PaymentsSummary } from './api'
import { CashBookCard } from './CashBookCard'
import { DealsCard } from './DealsCard'
import { ManualEntryCard } from './ManualEntryCard'

const shiftMonth = (month: string, by: number) => {
  const [year, m] = month.split('-').map(Number)
  const date = new Date(Date.UTC(year, m - 1 + by, 1))
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}

const lastDay = (month: string) => {
  const [year, m] = month.split('-').map(Number)
  return `${month}-${String(new Date(Date.UTC(year, m, 0)).getUTCDate()).padStart(2, '0')}`
}

/**
 * Payments & invoices (docs/phase-5-admin-core.md §4.6): the company balance (only with its permission — the API
 * refuses it otherwise), the month's money by method, online payments to review, the cash book, manual cash in/out and
 * deals. Every figure comes from the append-only cash book and journal.
 */
export function PaymentsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { dateRange } = useFormat()
  const thisMonth = todayInDhaka().slice(0, 7)
  const [month, setMonth] = useState(thisMonth)
  const summary = usePaymentsSummary(month)

  return (
    <>
      <PageHeader title={t('payments.title')} subtitle={t('payments.subtitle')} />

      <div className="flex flex-wrap items-center gap-3" data-testid="payments-month">
        <button type="button" className={buttonClass('outline', 'icon', 'size-8.5 text-14')} aria-label={t('payments.previousMonth')} onClick={() => setMonth(shiftMonth(month, -1))}>
          ‹
        </button>
        <span className="min-w-40 text-center font-display text-16 font-semibold">{dateRange(`${month}-01`, lastDay(month))}</span>
        <button type="button" className={buttonClass('outline', 'icon', 'size-8.5 text-14')} aria-label={t('payments.nextMonth')} disabled={month >= thisMonth} onClick={() => setMonth(shiftMonth(month, 1))}>
          ›
        </button>
      </div>

      {summary.isError ? <ErrorNotice error={summary.error} /> : <SummaryCards summary={summary.data ?? null} />}
      {summary.data && summary.data.review_count > 0 ? <ReviewCard /> : null}

      <div className="grid items-start gap-4.5 xl:grid-cols-[minmax(0,1fr)_minmax(300px,400px)]">
        <CashBookCard />
        <div className="flex flex-col gap-4.5">
          {can('transactions.create_manual') ? <ManualEntryCard /> : null}
          <DealsCard />
        </div>
      </div>
    </>
  )
}

function SummaryCards({ summary }: { summary: PaymentsSummary | null }) {
  const { t } = useTranslation()
  const { bdt, bdtCompact, number } = useFormat()

  return (
    <div className="grid-auto-fit-220 grid gap-3.5" data-testid="payment-cards">
      {summary?.balance ? <BalanceCard balance={summary.balance} /> : null}
      {(summary?.methods ?? []).map((card) => (
        <div key={card.key} className="flex flex-col gap-1 rounded-16 border border-app-line bg-app-surface px-5 py-4.5" data-testid={`method-${card.key}`}>
          <span className="text-13 text-app-muted">{t(`payments.method.${card.key}`)}</span>
          <span className="font-display text-22 font-extrabold" title={bdt(card.amount)}>
            {bdtCompact(card.amount)}
          </span>
          <span className="text-12 text-app-muted">{t('payments.paymentsThisMonth', { count: card.payments, n: number(card.payments) })}</span>
        </div>
      ))}
      {summary ? (
        <div className="flex flex-col gap-1 rounded-16 border border-app-line bg-app-surface px-5 py-4.5">
          <span className="text-13 text-app-muted">{t('payments.collected')}</span>
          <span className="font-display text-22 font-extrabold text-green" data-testid="payments-collected">
            {bdt(summary.collected)}
          </span>
          <span className="text-12 text-app-muted">{t('payments.invoicedNote', { amount: bdt(summary.invoiced) })}</span>
        </div>
      ) : null}
    </div>
  )
}

/** The prototype's blue card. Only rendered when the API sent the balance — it is withheld without the permission. */
function BalanceCard({ balance }: { balance: Balance }) {
  const { t } = useTranslation()
  const { bdt, locale } = useFormat()
  const { can } = useAuth()
  const [openingFor, setOpeningFor] = useState(false)
  const missing = balance.accounts.filter((account) => !account.opening)

  return (
    <div className="flex flex-col gap-1.5 rounded-16 bg-linear-135/srgb from-blue to-blue-deep px-5 py-5 text-white" data-testid="company-balance">
      <span className="text-13 opacity-85">{t('payments.companyBalance')}</span>
      <span className="font-display text-32 font-extrabold">{bdt(balance.total)}</span>
      <ul className="m-0 flex list-none flex-col gap-0.5 p-0 text-12 opacity-90">
        {balance.accounts.map((account) => (
          <li key={account.code} className="flex justify-between gap-3">
            <span>{locale === 'bn' ? account.name_bn : account.name_en}</span>
            <span className="font-display">{bdt(account.balance)}</span>
          </li>
        ))}
      </ul>
      {missing.length > 0 && can('transactions.create_manual') ? (
        <button type="button" className="mt-1 cursor-pointer self-start rounded-8 border border-white/40 bg-transparent px-2.5 py-1 text-12 font-semibold text-white hover:bg-white/10" onClick={() => setOpeningFor(true)}>
          {t('payments.setOpening')}
        </button>
      ) : null}
      {openingFor ? <OpeningBalanceDialog accounts={missing} onClose={() => setOpeningFor(false)} /> : null}
    </div>
  )
}

function OpeningBalanceDialog({ accounts, onClose }: { accounts: Balance['accounts']; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, locale } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState({ account: accounts[0]?.code ?? '', amount: null as number | null, as_of: todayInDhaka(), note: '' })
  const save = usePaymentsMutation(() => paymentActions.openingBalance({ account: form.account, amount: form.amount ?? 0, as_of: form.as_of, note: form.note || null }))

  return (
    <Dialog open onClose={onClose} title={t('payments.openingTitle')}>
      <p className="m-0 text-13 text-app-muted">{t('payments.openingNote')}</p>
      <SelectInput label={t('payments.account')} value={form.account} onChange={(account) => setForm({ ...form, account })} options={accounts.map((a) => ({ value: a.code, label: locale === 'bn' ? a.name_bn : a.name_en }))} />
      <NumberInput label={t('payments.amount')} value={form.amount} onChange={(amount) => setForm({ ...form, amount })} preview={(value) => bdt(value)} />
      <TextInput label={t('payments.asOf')} type="date" max={todayInDhaka()} value={form.as_of} onChange={(as_of) => setForm({ ...form, as_of })} />
      <TextArea label={t('payments.note')} value={form.note} onChange={(note) => setForm({ ...form, note })} rows={2} />
      {save.error ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!form.account || form.amount === null || save.isPending}
          onClick={() =>
            save.mutate(undefined, {
              onSuccess: () => {
                toast(t('payments.openingSaved'))
                onClose()
              },
            })
          }
        >
          {t('payments.openingSubmit')}
        </button>
      </div>
    </Dialog>
  )
}

/** SSLCommerz payments held for review, or settled above what the customer was shown. */
function ReviewCard() {
  const { t } = useTranslation()
  const { bdt, dateTime } = useFormat()
  const queue = useReviewQueue(true)
  const [open, setOpen] = useState<number | null>(null)

  return (
    <Card>
      <CardTitle bn="অনলাইন পেমেন্ট যাচাই" en="Online payments needing review" aside={<span className="text-12 text-amber">{t('payments.reviewNote')}</span>} />
      {queue.isError ? <ErrorNotice error={queue.error} /> : null}
      <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="review-queue">
        {(queue.data ?? []).map((attempt) => (
          <li key={attempt.id} className="flex flex-wrap items-center justify-between gap-2 rounded-10 border border-orange-tint bg-orange-tint/40 px-3 py-2.5">
            <span className="flex min-w-0 flex-col">
              <span className="text-14 font-medium">
                <Link to={`/bookings/${attempt.booking.id}`} className="font-display font-semibold">
                  {attempt.booking.reference}
                </Link>{' '}
                · {attempt.booking.customer ?? '—'} · {t(`payments.reviewReason.${attempt.reason ?? 'other'}`, { defaultValue: attempt.reason ?? '' })}
              </span>
              <span className="text-12 text-app-muted">
                {attempt.tran_id} · {t('payments.shownCollected', { shown: bdt(attempt.amount + attempt.online_charge), collected: attempt.gateway_amount === null ? '—' : bdt(attempt.gateway_amount) })} · {dateTime(attempt.at)}
              </span>
            </span>
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setOpen(attempt.id)}>
              {t('payments.markReviewed')}
            </button>
          </li>
        ))}
      </ul>
      {open !== null ? <ReviewDialog id={open} onClose={() => setOpen(null)} /> : null}
    </Card>
  )
}

function ReviewDialog({ id, onClose }: { id: number; onClose: () => void }) {
  const { t } = useTranslation()
  const [note, setNote] = useState('')
  const review = usePaymentsMutation(() => paymentActions.review(id, note.trim()))

  return (
    <Dialog open onClose={onClose} title={t('payments.markReviewed')}>
      <TextArea label={t('payments.reviewWhat')} value={note} onChange={setNote} rows={3} hint={t('payments.reviewHint')} />
      {review.error ? <ErrorNotice error={review.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={note.trim().length < 3 || review.isPending} onClick={() => review.mutate(undefined, { onSuccess: onClose })}>
          {t('payments.markReviewed')}
        </button>
      </div>
    </Dialog>
  )
}
