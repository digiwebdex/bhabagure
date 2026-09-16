import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentOptions, usePaymentsMutation, type Balance, type CashEntry } from '../payments/api'

/**
 * The ways money is written into the cash book by hand (docs/phase-9-accounts.md §7): cash in, cash out, VAT handed
 * over to the government, and money moved between the company's own accounts. Each asks for the account rather than
 * the method, because the account is what the books are read by — and it tells the office drawer from a staff float.
 */
export function CashEntryDialog({ direction, onClose }: { direction: 'in' | 'out'; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()
  const [form, setForm] = useState({ account: '', category: '', amount: null as number | null, description: '', occurred_on: todayInDhaka(), business_line: '' })
  const [evidence, setEvidence] = useState<File | null>(null)

  const save = usePaymentsMutation(() => {
    const body = new FormData()
    body.append('direction', direction)
    body.append('account', form.account)
    body.append('category', form.category)
    body.append('amount', String(form.amount ?? 0))
    body.append('description', form.description.trim())
    body.append('occurred_on', form.occurred_on)
    if (form.business_line) body.append('business_line', form.business_line)
    if (evidence) body.append('evidence', evidence)
    return paymentActions.createEntry(body)
  })
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const categories = options.data?.categories[direction] ?? []
  const ready = form.account !== '' && form.category !== '' && (form.amount ?? 0) > 0 && form.description.trim().length >= 3 && evidence !== null && evidenceReady(evidence)

  return (
    <Dialog open onClose={onClose} title={t(direction === 'in' ? 'transactions.addCashIn' : 'transactions.addCashOut')}>
      <div className="grid-auto-fit-200 grid gap-3">
        <TextInput label={t('transactions.date')} type="date" max={todayInDhaka()} value={form.occurred_on} onChange={(occurred_on) => setForm({ ...form, occurred_on })} error={fieldError('occurred_on')} />
        <SelectInput
          label={t('transactions.account')}
          value={form.account}
          onChange={(account) => setForm({ ...form, account })}
          options={[{ value: '', label: t('transactions.chooseAccount') }, ...(options.data?.money_accounts ?? []).map((row) => ({ value: row.code, label: row.name_en || row.name_bn }))]}
          error={fieldError('account')}
        />
        <SelectInput
          label={t('transactions.category')}
          value={form.category}
          onChange={(category) => setForm({ ...form, category })}
          options={[{ value: '', label: t('transactions.chooseCategory') }, ...categories.map((value) => ({ value, label: t(`payments.category.${value}`) }))]}
          error={fieldError('category')}
        />
        <NumberInput label={t('transactions.amount')} value={form.amount} onChange={(amount) => setForm({ ...form, amount })} preview={(value) => bdt(value)} error={fieldError('amount')} />
      </div>
      <TextArea label={t('transactions.description')} value={form.description} onChange={(description) => setForm({ ...form, description })} rows={2} error={fieldError('description')} />
      <SelectInput
        label={t('payments.source')}
        value={form.business_line}
        onChange={(business_line) => setForm({ ...form, business_line })}
        options={[{ value: '', label: t('common.none') }, ...(options.data?.business_lines ?? []).map((value) => ({ value, label: t(`payments.line.${value}`) }))]}
      />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />

      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <Footer
        onClose={onClose}
        label={t(direction === 'in' ? 'transactions.saveCashIn' : 'transactions.saveCashOut')}
        disabled={!ready || save.isPending}
        pending={save.isPending}
        onSave={() => save.mutate(undefined, { onSuccess: () => { toast(t('transactions.saved')); onClose() } })}
      />
    </Dialog>
  )
}

/** VAT collected from customers, handed over to the government: the only thing that brings that balance back down. */
export function VatPaymentDialog({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()
  const [form, setForm] = useState({ account: '', amount: null as number | null, description: '', occurred_on: todayInDhaka() })
  const [evidence, setEvidence] = useState<File | null>(null)

  const save = usePaymentsMutation(() => {
    const body = new FormData()
    body.append('account', form.account)
    body.append('amount', String(form.amount ?? 0))
    body.append('occurred_on', form.occurred_on)
    if (form.description.trim()) body.append('description', form.description.trim())
    if (evidence) body.append('evidence', evidence)
    return paymentActions.payVat(body)
  })
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const ready = form.account !== '' && (form.amount ?? 0) > 0 && evidence !== null && evidenceReady(evidence)

  return (
    <Dialog open onClose={onClose} title={t('transactions.vatTitle')}>
      <p className="m-0 text-13 text-app-muted">{t('transactions.vatNote')}</p>
      <div className="grid-auto-fit-200 grid gap-3">
        <TextInput label={t('transactions.date')} type="date" max={todayInDhaka()} value={form.occurred_on} onChange={(occurred_on) => setForm({ ...form, occurred_on })} error={fieldError('occurred_on')} />
        <SelectInput
          label={t('transactions.account')}
          value={form.account}
          onChange={(account) => setForm({ ...form, account })}
          options={[{ value: '', label: t('transactions.chooseAccount') }, ...(options.data?.money_accounts ?? []).map((row) => ({ value: row.code, label: row.name_en || row.name_bn }))]}
          error={fieldError('account')}
        />
        <NumberInput label={t('transactions.amount')} value={form.amount} onChange={(amount) => setForm({ ...form, amount })} preview={(value) => bdt(value)} error={fieldError('amount')} />
      </div>
      <TextArea label={t('transactions.description')} value={form.description} onChange={(description) => setForm({ ...form, description })} rows={2} error={fieldError('description')} />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />

      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <Footer onClose={onClose} label={t('transactions.pay')} disabled={!ready || save.isPending} pending={save.isPending} onSave={() => save.mutate(undefined, { onSuccess: () => { toast(t('transactions.vatPaid')); onClose() } })} />
    </Dialog>
  )
}

/**
 * Money moved from one of the company's own accounts to another — cash banked, a float handed over. The company
 * balance is the same afterwards, so this is a journal entry and never a cash book row.
 */
export function TransferDialog({ accounts, onClose }: { accounts: Balance['accounts']; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [form, setForm] = useState({ from: accounts[0]?.code ?? '', to: accounts[1]?.code ?? '', amount: null as number | null, description: '', occurred_on: todayInDhaka() })
  const save = usePaymentsMutation(() =>
    paymentActions.transfer({ from: form.from, to: form.to, amount: form.amount ?? 0, description: form.description.trim(), occurred_on: form.occurred_on }),
  )
  const held = accounts.find((account) => account.code === form.from)?.balance ?? 0
  const options = accounts.map((account) => ({ value: account.code, label: account.name_en || account.name_bn }))
  const tooMuch = (form.amount ?? 0) > held

  return (
    <Dialog open onClose={onClose} title={t('payments.moveTitle')}>
      <p className="m-0 text-13 text-app-muted">{t('payments.moveNote')}</p>
      <div className="grid-auto-fit-200 grid gap-3">
        <TextInput label={t('transactions.date')} type="date" max={todayInDhaka()} value={form.occurred_on} onChange={(occurred_on) => setForm({ ...form, occurred_on })} />
        <SelectInput label={t('payments.moveFrom')} value={form.from} onChange={(from) => setForm({ ...form, from })} options={options} hint={t('payments.holds', { amount: bdt(held) })} />
        <SelectInput label={t('payments.moveTo')} value={form.to} onChange={(to) => setForm({ ...form, to })} options={options.filter((option) => option.value !== form.from)} />
        <NumberInput label={t('transactions.amount')} value={form.amount} onChange={(amount) => setForm({ ...form, amount })} preview={(value) => bdt(value)} />
      </div>
      <TextInput label={t('payments.moveWhy')} value={form.description} onChange={(description) => setForm({ ...form, description })} hint={t('payments.moveWhyHint')} />
      {tooMuch ? <p className="m-0 text-13 text-red">{t('payments.moveTooMuch', { amount: bdt(held) })}</p> : null}
      {save.error ? <ErrorNotice error={save.error} /> : null}
      <Footer
        onClose={onClose}
        label={t('payments.moveSubmit')}
        disabled={!form.from || !form.to || form.from === form.to || !form.amount || tooMuch || form.description.trim().length < 3 || save.isPending}
        pending={save.isPending}
        onSave={() => save.mutate(undefined, { onSuccess: () => { toast(t('payments.moved')); onClose() } })}
      />
    </Dialog>
  )
}

/** A mistake is corrected by an opposite entry with a reason; the original stays where it is, for good. */
export function ReverseDialog({ entry, onClose }: { entry: CashEntry; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reverse = usePaymentsMutation(() => paymentActions.reverse(entry.id, reason.trim()))

  return (
    <Dialog open onClose={onClose} title={t('payments.reverseTitle', { amount: bdt(entry.amount) })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('payments.reverseNote')}</p>
      <p className="m-0 text-14 font-medium">{entry.description}</p>
      <TextArea label={t('bookings.reason')} value={reason} onChange={setReason} rows={2} />
      {reverse.error ? <ErrorNotice error={reverse.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || reverse.isPending}
          onClick={() => reverse.mutate(undefined, { onSuccess: () => { toast(t('payments.reversed')); onClose() } })}
        >
          {t('payments.reverse')}
        </button>
      </div>
    </Dialog>
  )
}

function Footer({ onClose, label, disabled, pending, onSave }: { onClose: () => void; label: string; disabled: boolean; pending: boolean; onSave: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex justify-end gap-2">
      <button type="button" className={buttonClass('outline')} onClick={onClose}>
        {t('common.cancel')}
      </button>
      <button type="button" className={buttonClass('primary')} disabled={disabled} onClick={onSave}>
        {pending ? t('common.saving') : label}
      </button>
    </div>
  )
}
