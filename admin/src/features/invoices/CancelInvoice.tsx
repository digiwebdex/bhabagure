import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextArea } from '../../components/ui/fields'
import { invoiceActions, useInvoiceAction, type InvoiceRow } from './api'

/**
 * Delete on an issued invoice (client, 2026-09-29; docs/phase-9-accounts.md §5): it is cancelled, not removed — it stays
 * in the list marked Cancelled, its number never goes missing, and its entry in the books is reversed. Money paid on it
 * is reversed in the cash book first.
 */
export function CancelInvoice({ invoice, onClose }: { invoice: InvoiceRow; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const cancel = useInvoiceAction(invoiceActions.void(invoice.id))
  const paid = invoice.paid > 0

  return (
    <Dialog open onClose={onClose} title={t('invoices.cancel.title', { number: invoice.number })}>
      <p className="m-0 text-14 leading-1.6">{paid ? t('invoices.cancel.paidFirst') : t('invoices.cancel.note')}</p>
      {!paid ? <TextArea label={t('invoices.cancel.reason')} value={reason} onChange={setReason} rows={2} autoFocus /> : null}
      {cancel.error ? <ErrorNotice error={cancel.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {paid ? t('common.close') : t('common.back')}
        </button>
        {!paid ? (
          <button
            type="button"
            className={buttonClass('cta')}
            disabled={reason.trim().length < 3 || cancel.isPending}
            onClick={() =>
              cancel.mutate(
                { reason: reason.trim() },
                {
                  onSuccess: () => {
                    toast(t('invoices.cancel.done', { number: invoice.number }))
                    onClose()
                  },
                },
              )
            }
          >
            {cancel.isPending ? t('common.saving') : t('invoices.cancel.confirm')}
          </button>
        ) : null}
      </div>
    </Dialog>
  )
}
