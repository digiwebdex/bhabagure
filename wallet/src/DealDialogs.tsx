import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { EntryIcons, type EntryAction } from './EntryDialogs'
import { AddPaymentIcon, DeleteIcon, EditIcon } from './icons'
import { ApiError } from './lib/api'
import { MAX_EVIDENCE_BYTES } from './lib/evidence'
import { todayInDhaka, useFormat } from './lib/format'
import { actions, useBookChange, useDeal, type Deal, type DealStep } from './lib/queries'
import { Dialog, Field, Notice, Pending, PrimaryButton, SecondaryButton, inputClass } from './ui'

/** The dialog open on a deal (docs/wallet-edits.md): View, Edit, Add payment or Delete. */
export type DealAction = { kind: 'view' | 'edit' | 'pay' | 'delete'; deal: Deal }

/** View moves on to the others in place, and to an advance's or payment's own View, Edit or Delete. */
export function DealDialog({
  action,
  onChange,
  onEntry,
  onClose,
  onDone,
}: {
  action: DealAction
  onChange: (next: DealAction) => void
  onEntry: (next: EntryAction) => void
  onClose: () => void
  onDone: (message: string) => void
}) {
  if (action.kind === 'view') return <DealView deal={action.deal} onChange={onChange} onEntry={onEntry} onClose={onClose} />
  if (action.kind === 'edit') return <DealEdit deal={action.deal} onClose={onClose} onDone={onDone} />
  if (action.kind === 'pay') return <DealPay deal={action.deal} onClose={onClose} onDone={onDone} />
  return <DealDelete deal={action.deal} onClose={onClose} onDone={onDone} />
}

function DealView({ deal, onChange, onEntry, onClose }: { deal: Deal; onChange: (next: DealAction) => void; onEntry: (next: EntryAction) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const view = useDeal(deal.id)
  // The card's own data at once; the history follows.
  const shown: Deal = view.data ?? deal

  return (
    <Dialog title={t('deals.viewTitle', { name: shown.name })} onClose={onClose}>
      <span className="grid grid-cols-3 gap-2 rounded-14 border border-wallet-line bg-wallet-bg p-3.5 text-12">
        {[
          [t('deals.total'), bdt(shown.total), 'text-wallet-text'],
          [t('deals.received'), bdt(shown.received), 'text-wallet-in-soft'],
          [t('deals.due'), bdt(shown.due), shown.due > 0 ? 'text-wallet-due' : 'text-wallet-muted'],
        ].map(([label, figure, color]) => (
          <span key={label} className="flex flex-col">
            <span className="text-wallet-muted">{label}</span>
            <span className={`font-display text-15 font-bold ${color}`}>{figure}</span>
          </span>
        ))}
      </span>
      {shown.note ? <p className="m-0 text-13 leading-1.55 text-wallet-muted">{shown.note}</p> : null}
      <section className="flex flex-col gap-2">
        <h3 className="m-0 text-14 font-semibold">{t('deals.payments')}</h3>
        {shown.payments.length === 0 ? (
          <p className="m-0 text-13 text-wallet-muted">{t('deals.noPayments')}</p>
        ) : (
          <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="deal-view-payments">
            {shown.payments.map((payment) => (
              <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-10 border border-wallet-line px-3 py-2 text-13">
                <span className="flex min-w-0 flex-col">
                  <span className="truncate">{payment.reference}</span>
                  <span className="text-12 text-wallet-muted">{date(payment.occurred_on)}</span>
                </span>
                <span className="flex items-center gap-2.5">
                  <span className="font-display font-bold text-wallet-in-soft">+ {bdt(payment.amount)}</span>
                  <EntryIcons entry={payment} onOpen={onEntry} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
      <section className="flex flex-col gap-2">
        <h3 className="m-0 text-14 font-semibold">{t('deals.history')}</h3>
        {view.data ? <DealSteps steps={view.data.history} /> : <Pending error={view.error} />}
      </section>
      <div className="flex flex-wrap justify-end gap-2">
        <SecondaryButton onClick={() => onChange({ kind: 'edit', deal: shown })}>
          <EditIcon /> {t('deals.editShort')}
        </SecondaryButton>
        {shown.settled ? null : (
          <SecondaryButton onClick={() => onChange({ kind: 'pay', deal: shown })}>
            <AddPaymentIcon /> {t('deals.addPaymentShort')}
          </SecondaryButton>
        )}
        <SecondaryButton tone="out" onClick={() => onChange({ kind: 'delete', deal: shown })}>
          <DeleteIcon /> {t('deals.deleteShort')}
        </SecondaryButton>
      </div>
    </Dialog>
  )
}

/** A deal's own history: made with its total and advance, then each edit with what changed and why. */
function DealSteps({ steps }: { steps: DealStep[] }) {
  const { t } = useTranslation()
  const { bdt, dateTime } = useFormat()
  const when = (iso: string | null) => (iso ? dateTime(iso) : '')
  const describe = (field: string, [before, after]: [string | number | null, string | number | null]) => {
    const show = (value: string | number | null) => (value === null || value === '' ? t('deals.noNote') : field === 'total' ? bdt(Number(value)) : `“${value}”`)
    return `${t(`deals.fields.${field}`)}: ${show(before)} → ${show(after)}`
  }

  return (
    <ol className="m-0 flex list-none flex-col gap-2.5 border-l-2 border-wallet-line p-0 pl-3.5" data-testid="deal-history">
      {steps.map((step, index) =>
        step.action === 'deal_created' ? (
          <li key={index} className="flex flex-col gap-0.5">
            <span className="text-13 font-semibold">{t('deals.steps.made', { when: when(step.at) })}</span>
            {step.changes ? <span className="text-12 text-wallet-muted">{t('deals.steps.madeWith', { total: bdt(step.changes.total), advance: bdt(step.changes.advance) })}</span> : null}
          </li>
        ) : (
          <li key={index} className="flex flex-col gap-0.5">
            <span className="text-13 font-semibold">{t('deals.steps.edited', { when: when(step.at) })}</span>
            {Object.entries(step.changes?.changes ?? {}).map(([field, pair]) =>
              pair ? (
                <span key={field} className="text-12 text-wallet-muted">
                  {describe(field, pair)}
                </span>
              ) : null,
            )}
            {step.changes?.reason ? <span className="text-12 text-wallet-lilac">{t('entry.reasonLine', { reason: step.changes.reason })}</span> : null}
          </li>
        ),
      )}
    </ol>
  )
}

/** Name, total and note. The total can't go below what the deal has received. */
function DealEdit({ deal, onClose, onDone }: { deal: Deal; onClose: () => void; onDone: (message: string) => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [name, setName] = useState(deal.name)
  const [total, setTotal] = useState(String(deal.total))
  const [note, setNote] = useState(deal.note ?? '')
  const [reason, setReason] = useState('')
  const save = useBookChange(actions.editDeal)
  const fieldError = (field: string) => (save.error instanceof ApiError ? save.error.field(field) : undefined)

  const value = Number(total)
  const belowReceived = value > 0 && value < deal.received
  const changed = name.trim() !== deal.name || value !== deal.total || note.trim() !== (deal.note ?? '')
  const ready = changed && name.trim().length >= 2 && value >= 1 && !belowReceived

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!ready) return
    save.mutate(
      { id: deal.id, name: name.trim(), total: value, note: note.trim(), reason: reason.trim() },
      {
        onSuccess: () => {
          onDone(t('deals.editedDone', { name: name.trim() }))
          onClose()
        },
      },
    )
  }

  return (
    <Dialog title={t('deals.editTitle', { name: deal.name })} onClose={onClose}>
      <form className="flex flex-col gap-3.5" onSubmit={submit} data-testid="deal-edit">
        <Field label={t('deals.name')} error={fieldError('name')}>
          <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoFocus />
        </Field>
        <Field label={t('deals.total')} error={fieldError('total')} hint={deal.received > 0 ? t('deals.totalHint', { received: bdt(deal.received) }) : undefined}>
          <input className={inputClass} type="number" min={Math.max(1, deal.received)} inputMode="decimal" value={total} onChange={(e) => setTotal(e.target.value)} />
        </Field>
        {belowReceived ? <Notice tone="out">{t('deals.totalBelowReceived', { received: bdt(deal.received) })}</Notice> : null}
        <Field label={t('deals.note')} error={fieldError('note')}>
          <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} placeholder={t('deals.notePlaceholder')} />
        </Field>
        <Field label={t('entry.reasonOptional')}>
          <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} placeholder={t('entry.reasonPlaceholder')} />
        </Field>
        {save.error && !(save.error instanceof ApiError && save.error.status === 422 && !save.error.code) ? <Notice tone="out">{save.error.message}</Notice> : null}
        <div className="flex flex-wrap justify-end gap-2">
          <SecondaryButton onClick={onClose}>{t('history.cancel')}</SecondaryButton>
          <PrimaryButton type="submit" disabled={!ready || save.isPending}>
            {t('entry.save')}
          </PrimaryButton>
        </div>
      </form>
    </Dialog>
  )
}

/** Add payment: the amount (what is due to start with), the date, a reference and evidence. */
function DealPay({ deal, onClose, onDone }: { deal: Deal; onClose: () => void; onDone: (message: string) => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [amount, setAmount] = useState(String(deal.due))
  const [occurredOn, setOccurredOn] = useState(todayInDhaka())
  const [reference, setReference] = useState('')
  const [evidence, setEvidence] = useState<File | null>(null)
  const pay = useBookChange(actions.pay)
  const fieldError = (field: string) => (pay.error instanceof ApiError ? pay.error.field(field) : undefined)

  const value = Number(amount)
  const overDue = value > deal.due
  const tooBig = evidence !== null && evidence.size > MAX_EVIDENCE_BYTES
  const ready = value >= 1 && !overDue && occurredOn !== '' && !tooBig

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!ready) return
    const body = new FormData()
    body.append('amount', String(value))
    body.append('occurred_on', occurredOn)
    if (reference.trim()) body.append('reference', reference.trim())
    if (evidence) body.append('evidence', evidence)
    pay.mutate(
      { id: deal.id, form: body },
      {
        onSuccess: () => {
          onDone(t('deals.paid', { amount: bdt(value), name: deal.name }))
          onClose()
        },
      },
    )
  }

  return (
    <Dialog title={t('deals.payTitle', { name: deal.name })} onClose={onClose}>
      <form className="flex flex-col gap-3.5" onSubmit={submit} data-testid="deal-pay">
        <Field label={t('cash.amount')} error={fieldError('amount')} hint={t('deals.dueHint', { due: bdt(deal.due) })}>
          <input className={`${inputClass} font-display text-18`} type="number" min={1} max={deal.due} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
        </Field>
        {overDue ? <Notice tone="out">{t('deals.overDue', { due: bdt(deal.due) })}</Notice> : null}
        <Field label={t('cash.date')} error={fieldError('occurred_on')}>
          <input className={inputClass} type="date" max={todayInDhaka()} value={occurredOn} onChange={(e) => setOccurredOn(e.target.value)} />
        </Field>
        <Field label={t('deals.payReference')} error={fieldError('reference')}>
          <input className={inputClass} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={300} placeholder={t('deals.payReferencePlaceholder')} />
        </Field>
        <label className={`flex cursor-pointer items-center gap-3 rounded-12 border border-dashed p-3 ${evidence ? 'border-wallet-in-soft/45 bg-wallet-in/10' : 'border-wallet-input bg-wallet-bg'}`}>
          <span className="flex min-w-0 flex-1 flex-col">
            <span className="truncate text-13 font-semibold">{evidence ? evidence.name : t('cash.evidence')}</span>
            <span className={`text-12 ${tooBig ? 'text-wallet-out-soft' : 'text-wallet-muted'}`}>{tooBig ? t('cash.evidenceTooBig') : t('cash.evidenceNote')}</span>
          </span>
          <input type="file" accept="image/*,.pdf" className="sr-only" onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
        </label>
        {pay.error && !(pay.error instanceof ApiError && pay.error.status === 422 && !pay.error.code) ? <Notice tone="out">{pay.error.message}</Notice> : null}
        <div className="flex flex-wrap justify-end gap-2">
          <SecondaryButton onClick={onClose}>{t('history.cancel')}</SecondaryButton>
          <PrimaryButton type="submit" tone="in" disabled={!ready || pay.isPending}>
            {t('deals.savePayment')}
          </PrimaryButton>
        </div>
      </form>
    </Dialog>
  )
}

/** Delete, with its advance and payments: that money leaves the balance too (the owner's choice, 2026-10-09). */
function DealDelete({ deal, onClose, onDone }: { deal: Deal; onClose: () => void; onDone: (message: string) => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [reason, setReason] = useState('')
  const remove = useBookChange(actions.deleteDeal)

  return (
    <Dialog title={t('deals.deleteTitle', { name: deal.name })} onClose={onClose}>
      <p className="m-0 text-14 leading-1.55">{t('deals.deleteQuestion', { name: deal.name, total: bdt(deal.total) })}</p>
      {deal.received > 0 ? <Notice tone="out">{t('deals.deleteMoney', { amount: bdt(deal.received) })}</Notice> : <p className="m-0 text-13 text-wallet-muted">{t('deals.deleteNoMoney')}</p>}
      <Field label={t('entry.reasonOptional')}>
        <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} placeholder={t('deals.deleteReasonPlaceholder')} autoFocus />
      </Field>
      {remove.error ? <Notice tone="out">{remove.error.message}</Notice> : null}
      <div className="flex flex-wrap justify-end gap-2">
        <SecondaryButton onClick={onClose}>{t('history.cancel')}</SecondaryButton>
        <PrimaryButton
          tone="out"
          className="inline-flex items-center gap-1.5"
          disabled={remove.isPending}
          onClick={() =>
            remove.mutate(
              { id: deal.id, reason: reason.trim() },
              {
                onSuccess: () => {
                  onDone(t('deals.deletedDone', { name: deal.name }))
                  onClose()
                },
              },
            )
          }
        >
          <DeleteIcon /> {t('deals.deleteShort')}
        </PrimaryButton>
      </div>
    </Dialog>
  )
}
