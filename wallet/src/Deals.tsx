import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError } from './lib/api'
import { todayInDhaka, useFormat } from './lib/format'
import { actions, useBookChange, useDeals, type Deal } from './lib/queries'
import { Card, Notice, Pending, PrimaryButton, inputClass } from './ui'

/** Deals: a total, an advance no more than it (cash in now), then payments against what is due. */
export function Deals({ dueTotal }: { dueTotal: number }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const deals = useDeals()
  const [name, setName] = useState('')
  const [total, setTotal] = useState('')
  const [advance, setAdvance] = useState('')
  const [note, setNote] = useState('')
  const [done, setDone] = useState<string | null>(null)
  const create = useBookChange(actions.createDeal)

  const totalValue = Number(total)
  const advanceValue = Number(advance || 0)
  const overTotal = totalValue > 0 && advanceValue > totalValue
  const ready = name.trim().length >= 2 && totalValue >= 1 && !overTotal

  return (
    <Card>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="m-0 text-16 font-semibold">{t('deals.title')}</h2>
        <span className="font-display text-13 font-semibold text-wallet-due" data-testid="due-total">
          {t('deals.dueTotal', { amount: bdt(dueTotal) })}
        </span>
      </div>

      <form
        className="flex flex-col gap-2.5 rounded-14 border border-wallet-line bg-wallet-bg p-3.5"
        data-testid="deal-form"
        onSubmit={(event) => {
          event.preventDefault()
          if (!ready) return
          const body = new FormData()
          body.append('name', name.trim())
          body.append('total', String(totalValue))
          body.append('advance', String(advanceValue))
          if (note.trim()) body.append('note', note.trim())
          body.append('occurred_on', todayInDhaka())
          create.mutate(body, {
            onSuccess: () => {
              setDone(advanceValue > 0 ? t('deals.savedWithAdvance', { amount: bdt(advanceValue) }) : t('deals.saved'))
              setName('')
              setTotal('')
              setAdvance('')
              setNote('')
            },
          })
        }}
      >
        <span className="text-13 font-semibold text-wallet-lilac">{t('deals.new')}</span>
        <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} placeholder={t('deals.namePlaceholder')} aria-label={t('deals.name')} maxLength={120} />
        <div className="grid grid-cols-2 gap-2.5">
          <label className="flex flex-col gap-1 text-12 text-wallet-muted">
            {t('deals.total')}
            <input className={inputClass} type="number" min={1} value={total} onChange={(e) => setTotal(e.target.value)} placeholder="20000" />
          </label>
          <label className="flex flex-col gap-1 text-12 text-wallet-muted">
            {t('deals.advance')}
            <input className={inputClass} type="number" min={0} value={advance} onChange={(e) => setAdvance(e.target.value)} placeholder="10000" />
          </label>
        </div>
        <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder={t('deals.notePlaceholder')} aria-label={t('deals.note')} maxLength={300} />
        {totalValue >= 1 && !overTotal ? <p className="m-0 text-12 text-wallet-lilac">{t('deals.preview', { advance: bdt(advanceValue), due: bdt(Math.max(0, totalValue - advanceValue)) })}</p> : null}
        {overTotal ? <Notice tone="out">{t('deals.advanceOverTotal')}</Notice> : null}
        {create.error ? <Notice tone="out">{create.error.message}</Notice> : null}
        {done ? <Notice tone="in">{done}</Notice> : null}
        <PrimaryButton type="submit" disabled={!ready || create.isPending}>
          {t('deals.save')}
        </PrimaryButton>
      </form>

      {!deals.data ? (
        <Pending error={deals.error} />
      ) : deals.data.length === 0 ? (
        <p className="m-0 text-13 text-wallet-muted">{t('deals.empty')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0" data-testid="deals">
          {deals.data.map((deal) => (
            <DealCard key={deal.id} deal={deal} />
          ))}
        </ul>
      )}
    </Card>
  )
}

function DealCard({ deal }: { deal: Deal }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const [amount, setAmount] = useState(String(deal.due))
  const pay = useBookChange(actions.pay)
  const value = Number(amount)

  return (
    <li className={`flex flex-col gap-2.5 rounded-14 border p-3.5 ${deal.settled ? 'border-wallet-in-soft/30' : 'border-wallet-line'}`}>
      <span className="flex flex-wrap items-start justify-between gap-2">
        <span className="flex min-w-0 flex-col">
          <span className="font-semibold">{deal.name}</span>
          <span className="text-12 text-wallet-muted">
            {deal.note ? `${deal.note} · ` : ''}
            {deal.created_at ? date(deal.created_at) : ''}
          </span>
        </span>
        <span className={`rounded-pill px-2.5 py-0.75 text-11 font-bold whitespace-nowrap ${deal.settled ? 'bg-wallet-in/20 text-wallet-in-soft' : 'bg-wallet-due/15 text-wallet-due'}`}>
          {deal.settled ? t('deals.settled') : t('deals.due')}
        </span>
      </span>
      <span className="grid grid-cols-3 gap-2 text-12">
        {[
          [t('deals.total'), bdt(deal.total), 'text-wallet-text'],
          [t('deals.received'), bdt(deal.received), 'text-wallet-in-soft'],
          [t('deals.due'), bdt(deal.due), deal.due > 0 ? 'text-wallet-due' : 'text-wallet-muted'],
        ].map(([label, figure, color]) => (
          <span key={label} className="flex flex-col">
            <span className="text-wallet-muted">{label}</span>
            <span className={`font-display text-14 font-bold ${color}`}>{figure}</span>
          </span>
        ))}
      </span>
      <span className="h-1.5 overflow-hidden rounded-pill bg-wallet-bg">
        <span className="block h-full rounded-pill bg-gradient-to-r from-wallet-in-deep to-wallet-in-soft" style={{ width: `${Math.min(100, Math.round((deal.received / deal.total) * 100))}%` }} />
      </span>
      {!deal.settled ? (
        <span className="flex gap-2">
          <input className={`${inputClass} py-2`} type="number" min={1} max={deal.due} value={amount} onChange={(e) => setAmount(e.target.value)} aria-label={t('deals.paymentAmount', { name: deal.name })} />
          <button
            type="button"
            className="cursor-pointer rounded-10 border-0 bg-wallet-in-deep px-3.5 text-13 font-bold whitespace-nowrap text-white disabled:opacity-50"
            disabled={value < 1 || value > deal.due || pay.isPending}
            onClick={() => {
              const body = new FormData()
              body.append('amount', String(value))
              body.append('occurred_on', todayInDhaka())
              pay.mutate({ id: deal.id, form: body })
            }}
          >
            {t('deals.recordPayment')}
          </button>
        </span>
      ) : null}
      {pay.error ? <Notice tone="out">{pay.error instanceof ApiError ? pay.error.message : String(pay.error)}</Notice> : null}
      {deal.payments.length > 0 ? (
        <ul className="m-0 flex list-none flex-col gap-1 p-0 text-12">
          {deal.payments.map((payment) => (
            <li key={payment.id} className={`flex justify-between gap-2 ${payment.reversed || payment.kind === 'reversal' ? 'text-wallet-faint line-through' : 'text-wallet-muted'}`}>
              <span>
                {date(payment.occurred_on)} · {payment.reference}
              </span>
              <span className="font-display">{payment.kind === 'reversal' ? '−' : '+'} {bdt(payment.amount)}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  )
}
