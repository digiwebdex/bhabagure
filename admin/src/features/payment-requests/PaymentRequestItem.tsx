import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, Switch, TextArea } from '../../components/ui/fields'
import { Badge } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { openPaymentScreenshot, paymentRequestActions, usePaymentRequestAction, type PaymentRequestRow } from './api'

const TONES = { pending: 'orange', approved: 'green', rejected: 'red' } as const

/**
 * One customer payment request (docs/payment-requests.md): what they say they paid, where and when, their screenshot, and
 * Approve (records the payment, confirms an inquiry) or Reject with a reason they read.
 */
export function PaymentRequestItem({ row, canDecide, bkashChargePercent, showBooking = false }: { row: PaymentRequestRow; canDecide: boolean; bkashChargePercent: number | null; showBooking?: boolean }) {
  const { t } = useTranslation()
  const { bdt, date, dateTime } = useFormat()
  const toast = useToast()
  const [approving, setApproving] = useState(false)
  const [rejecting, setRejecting] = useState(false)

  return (
    <li className="flex flex-col gap-2 border-b border-app-line py-3 last:border-b-0" data-testid={`payment-request-${row.id}`}>
      <span className="flex flex-wrap items-start justify-between gap-3">
        <span className="flex min-w-0 flex-col gap-0.5">
          <strong className="font-display text-15">{bdt(row.approved_amount ?? row.amount)}</strong>
          <span className="text-13 text-app-muted">
            {row.bank_name} · {t(`paymentRequests.methods.${row.method}`)} · {t('paymentRequests.paidOn', { date: date(row.paid_on) })}
            {row.reference ? ` · ${row.reference}` : ''}
          </span>
          {showBooking && row.booking.reference ? (
            <span className="text-12 text-app-muted">
              <Link to={`/bookings/${row.booking.id}`} className="font-semibold">
                {row.booking.reference}
              </Link>
              {row.booking.customer ? ` · ${row.booking.customer}` : ''}
              {row.booking.due !== undefined ? ` · ${t('paymentRequests.due', { amount: bdt(row.booking.due) })}` : ''}
            </span>
          ) : null}
          <span className="text-12 text-app-muted">
            {t(`paymentRequests.via.${row.via}`)}
            {row.created_at ? ` · ${dateTime(row.created_at)}` : ''}
          </span>
          {row.status !== 'pending' ? (
            <span className="text-12 text-app-muted">
              {t(`paymentRequests.decided.${row.status}`, { name: row.decided_by ?? '—', when: row.decided_at ? dateTime(row.decided_at) : '' })}
              {row.status === 'approved' && row.approved_amount !== null && row.approved_amount !== row.amount ? ` · ${t('paymentRequests.customerSaid', { amount: bdt(row.amount) })}` : ''}
              {row.decision_note ? ` · ${row.decision_note}` : ''}
            </span>
          ) : null}
        </span>
        <Badge tone={TONES[row.status]}>{t(`paymentRequests.status.${row.status}`)}</Badge>
      </span>
      <span className="flex flex-wrap gap-2">
        <button
          type="button"
          className={buttonClass('outline', 'sm')}
          onClick={async () => {
            if (!(await openPaymentScreenshot(row.id))) toast(t('evidence.openFailed'), 'error')
          }}
        >
          {t('paymentRequests.openScreenshot')}
        </button>
        {row.status === 'pending' && canDecide ? (
          <>
            <button type="button" className={buttonClass('success', 'sm')} onClick={() => setApproving(true)}>
              {t('paymentRequests.approve')}
            </button>
            <button type="button" className={buttonClass('outline', 'sm', 'text-red')} onClick={() => setRejecting(true)}>
              {t('paymentRequests.reject')}
            </button>
          </>
        ) : null}
      </span>
      {approving ? <ApproveDialog row={row} bkashChargePercent={bkashChargePercent} onClose={() => setApproving(false)} /> : null}
      {rejecting ? <RejectDialog row={row} onClose={() => setRejecting(false)} /> : null}
    </li>
  )
}

/** The amount found in the account (the customer's figure to start with), and for bKash whether they sent its charge too. */
function ApproveDialog({ row, bkashChargePercent, onClose }: { row: PaymentRequestRow; bkashChargePercent: number | null; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [amount, setAmount] = useState<number | null>(row.amount)
  const [bkashCharge, setBkashCharge] = useState(row.method === 'bkash' && (bkashChargePercent ?? 0) > 0)
  const approve = usePaymentRequestAction(paymentRequestActions.approve)
  const charge = row.method === 'bkash' && (bkashChargePercent ?? 0) > 0 && amount ? Math.round((amount * (bkashChargePercent ?? 0)) / 100) : 0
  const fieldError = approve.error instanceof ApiError ? approve.error : null

  return (
    <Dialog open onClose={onClose} title={t('paymentRequests.approveTitle', { amount: bdt(row.amount) })}>
      <p className="m-0 text-13 leading-1.55 text-app-muted">{t('paymentRequests.approveNote', { bank: row.bank_name })}</p>
      <NumberInput label={t('paymentRequests.amountReceived')} value={amount} onChange={setAmount} preview={(value) => bdt(value)} error={fieldError?.field('amount') ?? (fieldError?.code === 'over_due' ? fieldError.message : undefined)} />
      {charge > 0 ? <Switch label={t('bookings.bkashCharge', { amount: bdt(charge) })} hint={t('bookings.bkashChargeHint', { amount: bdt(amount ?? 0) })} checked={bkashCharge} onChange={setBkashCharge} /> : null}
      {approve.error && !(fieldError && (fieldError.status === 422 && (fieldError.field('amount') || fieldError.code === 'over_due'))) ? <ErrorNotice error={approve.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('success')}
          disabled={!amount || amount <= 0 || approve.isPending}
          onClick={() =>
            amount &&
            approve.mutate(
              { id: row.id, amount, bkashCharge: charge > 0 && bkashCharge },
              {
                onSuccess: () => {
                  toast(t('paymentRequests.approved', { amount: bdt(amount) }))
                  onClose()
                },
              },
            )
          }
        >
          {approve.isPending ? t('common.saving') : t('paymentRequests.approveButton')}
        </button>
      </div>
    </Dialog>
  )
}

function RejectDialog({ row, onClose }: { row: PaymentRequestRow; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reject = usePaymentRequestAction(paymentRequestActions.reject)

  return (
    <Dialog open onClose={onClose} title={t('paymentRequests.rejectTitle', { amount: bdt(row.amount) })}>
      <p className="m-0 text-13 leading-1.55 text-app-muted">{t('paymentRequests.rejectNote')}</p>
      <TextArea label={t('paymentRequests.reason')} value={reason} onChange={setReason} rows={3} maxLength={300} error={reject.error instanceof ApiError ? reject.error.field('reason') : undefined} />
      {reject.error && !(reject.error instanceof ApiError && reject.error.status === 422) ? <ErrorNotice error={reject.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('cta')}
          disabled={reason.trim().length < 3 || reject.isPending}
          onClick={() =>
            reject.mutate(
              { id: row.id, reason: reason.trim() },
              {
                onSuccess: () => {
                  toast(t('paymentRequests.rejected'))
                  onClose()
                },
              },
            )
          }
        >
          {t('paymentRequests.rejectButton')}
        </button>
      </div>
    </Dialog>
  )
}
