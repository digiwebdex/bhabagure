import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentOptions } from '../payments/api'
import { useInvoiceAction, type InvoiceRow } from './api'

/**
 * Money received against an invoice (docs/phase-9-accounts.md §5). It goes through the same endpoint the Payments
 * screen uses, so the cash book, the company balance and the ledger all see one payment — recorded here only to save
 * a trip to the other screen.
 *
 * Both the method and the account are asked for: cash into the office drawer and cash into a staff member's float are
 * the same method and different money. The receipt is required, as it is everywhere money is recorded by hand.
 */
export function InvoicePayment({ invoice, onClose }: { invoice: InvoiceRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()

  const [amount, setAmount] = useState<number | null>(invoice.due)
  const [method, setMethod] = useState('cash')
  const [account, setAccount] = useState('')
  const [occurredOn, setOccurredOn] = useState(todayInDhaka())
  const [reference, setReference] = useState('')
  const [evidence, setEvidence] = useState<File | null>(null)

  const record = useInvoiceAction(() =>
    paymentActions.payDeal(invoice.id, {
      amount: amount ?? 0,
      method,
      account: account || null,
      occurred_on: occurredOn,
      reference: reference.trim() || null,
      evidence: evidence!,
    }),
  )
  const fieldError = (name: string) => (record.error instanceof ApiError ? record.error.field(name) : undefined)
  const tooMuch = (amount ?? 0) > invoice.due
  const ready = (amount ?? 0) > 0 && !tooMuch && evidence !== null && evidenceReady(evidence)

  return (
    <Dialog open onClose={onClose} title={t('invoices.paymentTitle')}>
      <p className="m-0 text-14">
        <span className="text-app-muted">{t('invoices.totalDue')} </span>
        <strong className="font-display text-17 text-blue">{bdt(invoice.due)}</strong>
      </p>

      <div className="grid-auto-fit-200 grid gap-3">
        <SelectInput
          label={t('invoices.paymentMethod')}
          value={method}
          onChange={setMethod}
          options={(options.data?.methods ?? ['cash']).map((value) => ({ value, label: t(`bookings.methods.${value}`) }))}
          error={fieldError('method')}
        />
        {/* Left on "the usual account", the method decides; a float somebody holds is named here instead. */}
        <SelectInput
          label={t('invoices.paymentAccount')}
          value={account}
          onChange={setAccount}
          options={[{ value: '', label: t('invoices.usualAccount') }, ...(options.data?.money_accounts ?? []).map((row) => ({ value: row.code, label: row.name_en || row.name_bn }))]}
          error={fieldError('account')}
        />
        <TextInput label={t('invoices.paymentDate')} type="date" max={todayInDhaka()} value={occurredOn} onChange={setOccurredOn} error={fieldError('occurred_on')} />
        <NumberInput label={t('invoices.paymentAmount')} value={amount} onChange={setAmount} preview={(value) => bdt(value)} error={fieldError('amount')} />
      </div>

      <TextArea label={t('invoices.paymentReference')} value={reference} onChange={setReference} rows={2} hint={t('invoices.paymentReferenceHint')} error={fieldError('reference')} />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />

      {tooMuch ? <p className="m-0 text-13 text-red">{t('invoices.paymentTooHigh', { amount: bdt(invoice.due) })}</p> : null}
      {record.error && !(record.error instanceof ApiError && record.error.status === 422) ? <ErrorNotice error={record.error} /> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.close')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || record.isPending}
          onClick={() =>
            record.mutate(undefined, {
              onSuccess: () => {
                toast(t('invoices.paymentRecorded', { number: invoice.number ?? '' }))
                onClose()
              },
            })
          }
        >
          {record.isPending ? t('common.saving') : t('invoices.recordPayment')}
        </button>
      </div>
    </Dialog>
  )
}
