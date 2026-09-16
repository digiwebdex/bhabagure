import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentOptions } from '../payments/api'
import { useInvoiceAction, type InvoiceRow } from './api'

/**
 * Money received against an invoice. It goes through the same endpoint the Payments screen uses, so the cash book, the
 * company balance and the ledger all see one payment — recorded here only to save a trip to the other screen. The
 * receipt is required, as it is everywhere money is recorded by hand.
 */
export function InvoicePayment({ invoice, onClose }: { invoice: InvoiceRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()

  const [amount, setAmount] = useState<number | null>(invoice.due)
  const [method, setMethod] = useState('bkash')
  const [reference, setReference] = useState('')
  const [evidence, setEvidence] = useState<File | null>(null)

  const record = useInvoiceAction(() =>
    paymentActions.payDeal(invoice.id, { amount: amount ?? 0, method, reference: reference.trim() || null, evidence: evidence! }),
  )
  const fieldError = (name: string) => (record.error instanceof ApiError ? record.error.field(name) : undefined)
  const ready = (amount ?? 0) > 0 && (amount ?? 0) <= invoice.due && evidence !== null && evidenceReady(evidence)

  return (
    <Dialog open onClose={onClose} title={t('invoices.paymentTitle', { number: invoice.number ?? '' })}>
      <p className="text-13 text-app-muted">{t('invoices.paymentNote', { name: invoice.customer?.name ?? invoice.billed_name ?? '—', amount: bdt(invoice.due) })}</p>

      <div className="grid-auto-fit-200 grid gap-3">
        <NumberInput label={t('invoices.paymentAmount')} value={amount} onChange={setAmount} error={fieldError('amount')} />
        <SelectInput
          label={t('bookings.method')}
          value={method}
          onChange={setMethod}
          options={(options.data?.methods ?? ['bkash']).map((value) => ({ value, label: t(`bookings.methods.${value}`) }))}
          error={fieldError('method')}
        />
      </div>
      <TextInput label={t('invoices.paymentReference')} value={reference} onChange={setReference} hint={t('invoices.paymentReferenceHint')} error={fieldError('reference')} />
      <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />

      {(amount ?? 0) > invoice.due ? <p className="text-13 text-red">{t('invoices.paymentTooHigh', { amount: bdt(invoice.due) })}</p> : null}
      {record.error && !(record.error instanceof ApiError && record.error.status === 422) ? <ErrorNotice error={record.error} /> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
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
