import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice } from '../../components/ui/feedback'
import { TextArea } from '../../components/ui/fields'
import { Card, CardTitle } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentsMutation, useReviewQueue } from '../payments/api'

/**
 * SSLCommerz payments a person still has to look at — held for review, or settled above what the customer was shown.
 * It is an alert, not a list to work through, so the card is not there at all when the queue is empty.
 */
export function ReviewCard() {
  const { t } = useTranslation()
  const { bdt, dateTime } = useFormat()
  const queue = useReviewQueue(true)
  const [open, setOpen] = useState<number | null>(null)

  if (queue.isError) return <ErrorNotice error={queue.error} />
  if (!queue.data || queue.data.length === 0) return null

  return (
    <Card>
      <CardTitle title={t('payments.reviewTitle')} aside={<span className="text-12 text-amber">{t('payments.reviewNote')}</span>} />
      <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="review-queue">
        {queue.data.map((attempt) => (
          <li key={attempt.id} className="flex flex-wrap items-center justify-between gap-2 rounded-10 border border-orange-tint bg-orange-tint/40 px-3 py-2.5">
            <span className="flex min-w-0 flex-col">
              <span className="text-14 font-medium">
                <Link to={`/bookings/${attempt.booking.id}`} className="font-display font-semibold">
                  {attempt.booking.reference}
                </Link>{' '}
                · {attempt.booking.customer ?? '—'} · {t(`payments.reviewReason.${attempt.reason ?? 'other'}`, { defaultValue: attempt.reason ?? '' })}
              </span>
              <span className="text-12 text-app-muted">
                {attempt.tran_id} ·{' '}
                {t('payments.shownCollected', {
                  shown: bdt(attempt.amount + attempt.online_charge),
                  collected: attempt.gateway_amount === null ? '—' : bdt(attempt.gateway_amount),
                })}{' '}
                · {dateTime(attempt.at)}
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
