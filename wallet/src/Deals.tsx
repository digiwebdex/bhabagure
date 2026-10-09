import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { DealDialog, type DealAction } from './DealDialogs'
import { EntryDialog, EntryIcons, type EntryAction } from './EntryDialogs'
import { AddPaymentIcon, DeleteIcon, EditIcon, ViewIcon } from './icons'
import { todayInDhaka, useFormat } from './lib/format'
import { actions, useBookChange, useDeals, type Deal } from './lib/queries'
import { Card, IconButton, Notice, Pending, PrimaryButton, inputClass } from './ui'

/**
 * Deals: a total, an advance no more than it (cash in now), then payments against what is due. Each deal has View,
 * Edit, Add payment and Delete, and each advance or payment its own View, Edit and Delete (docs/wallet-edits.md).
 */
export function Deals({ dueTotal }: { dueTotal: number }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const deals = useDeals()
  const [name, setName] = useState('')
  const [total, setTotal] = useState('')
  const [advance, setAdvance] = useState('')
  const [note, setNote] = useState('')
  const [done, setDone] = useState<string | null>(null)
  const [dealOpen, setDealOpen] = useState<DealAction | null>(null)
  const [entryOpen, setEntryOpen] = useState<EntryAction | null>(null)
  const create = useBookChange(actions.createDeal)

  const totalValue = Number(total)
  const advanceValue = Number(advance || 0)
  const overTotal = totalValue > 0 && advanceValue > totalValue
  const ready = name.trim().length >= 2 && totalValue >= 1 && !overTotal
  // An advance's or payment's own dialog takes over from the deal's.
  const openEntry = (action: EntryAction) => {
    setDealOpen(null)
    setEntryOpen(action)
  }

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
        <PrimaryButton type="submit" disabled={!ready || create.isPending}>
          {t('deals.save')}
        </PrimaryButton>
      </form>

      {done ? <Notice tone="in">{done}</Notice> : null}
      {!deals.data ? (
        <Pending error={deals.error} />
      ) : deals.data.length === 0 ? (
        <p className="m-0 text-13 text-wallet-muted">{t('deals.empty')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0" data-testid="deals">
          {deals.data.map((deal) => (
            <DealCard key={deal.id} deal={deal} onOpen={setDealOpen} onEntry={openEntry} />
          ))}
        </ul>
      )}
      {dealOpen ? <DealDialog action={dealOpen} onChange={setDealOpen} onEntry={openEntry} onClose={() => setDealOpen(null)} onDone={setDone} /> : null}
      {entryOpen ? <EntryDialog action={entryOpen} onChange={setEntryOpen} onClose={() => setEntryOpen(null)} onDone={setDone} /> : null}
    </Card>
  )
}

function DealCard({ deal, onOpen, onEntry }: { deal: Deal; onOpen: (action: DealAction) => void; onEntry: (action: EntryAction) => void }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()

  return (
    <li className={`flex flex-col gap-2.5 rounded-14 border p-3.5 ${deal.settled ? 'border-wallet-in-soft/30' : 'border-wallet-line'}`}>
      <span className="flex flex-wrap items-start justify-between gap-2">
        <span className="flex min-w-0 flex-col">
          <span className="flex flex-wrap items-center gap-2">
            <span className="font-semibold">{deal.name}</span>
            <span className={`rounded-pill px-2.5 py-0.75 text-11 font-bold whitespace-nowrap ${deal.settled ? 'bg-wallet-in/20 text-wallet-in-soft' : 'bg-wallet-due/15 text-wallet-due'}`}>
              {deal.settled ? t('deals.settled') : t('deals.due')}
            </span>
          </span>
          <span className="text-12 text-wallet-muted">
            {deal.note ? `${deal.note} · ` : ''}
            {deal.created_at ? date(deal.created_at) : ''}
          </span>
        </span>
        <span className="flex gap-1.5">
          <IconButton label={t('deals.view', { name: deal.name })} tone="view" onClick={() => onOpen({ kind: 'view', deal })}>
            <ViewIcon />
          </IconButton>
          <IconButton label={t('deals.edit', { name: deal.name })} onClick={() => onOpen({ kind: 'edit', deal })}>
            <EditIcon />
          </IconButton>
          <IconButton label={deal.settled ? t('deals.nothingDue') : t('deals.addPayment', { name: deal.name })} tone="in" disabled={deal.settled} onClick={() => onOpen({ kind: 'pay', deal })}>
            <AddPaymentIcon />
          </IconButton>
          <IconButton label={t('deals.delete', { name: deal.name })} tone="out" onClick={() => onOpen({ kind: 'delete', deal })}>
            <DeleteIcon />
          </IconButton>
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
      {deal.payments.length > 0 ? (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0 text-12" data-testid="deal-payments">
          {deal.payments.map((payment) => (
            <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 text-wallet-muted">
              <span className="min-w-0">
                {date(payment.occurred_on)} · {payment.reference}
              </span>
              <span className="flex items-center gap-2.5">
                <span className="font-display text-wallet-text">+ {bdt(payment.amount)}</span>
                <EntryIcons entry={payment} onOpen={onEntry} />
              </span>
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  )
}
