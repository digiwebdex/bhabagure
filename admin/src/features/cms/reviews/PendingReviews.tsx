import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { NAV_COUNTS_KEY } from '../../../app/navCounts'
import { buttonClass } from '../../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../../components/ui/feedback'
import { TextArea } from '../../../components/ui/fields'
import { Badge, Card, CardTitle } from '../../../components/ui/layout'
import { api } from '../../../lib/api/client'
import type { Data, Review } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'

/**
 * Customers' reviews from the website waiting for staff (docs/customer-reviews.md): the stars, the text, the trip photos
 * (each can be kept off the website), and whether the number matched a booking. Approve puts it live; reject keeps it off
 * for good. Hidden while nothing waits.
 */
export function PendingReviews() {
  const { t } = useTranslation()
  const toast = useToast()
  const { number, digits, dateTime, month } = useFormat()
  const client = useQueryClient()
  const pending = useQuery({ queryKey: ['reviews', 'pending'], queryFn: ({ signal }) => api.get<Data<Review[]>>('admin/reviews/pending', signal) })
  const [rejecting, setRejecting] = useState<Review | null>(null)
  const [enlarged, setEnlarged] = useState<string | null>(null)
  const refresh = () => {
    void client.invalidateQueries({ queryKey: ['reviews'] })
    void client.invalidateQueries({ queryKey: NAV_COUNTS_KEY })
  }
  const approve = useMutation({ mutationFn: (id: number) => api.post<Data<Review>>(`admin/reviews/${id}/approve`), onSuccess: () => { refresh(); toast(t('reviews.approved')) } })
  // The tick changes at once and goes back if the save fails, rather than waiting for the list to reload.
  const setShown = (data: Data<Review[]> | undefined, id: number, shown: boolean) =>
    data ? { ...data, data: data.data.map((review) => ({ ...review, photos: review.photos.map((photo) => (photo.id === id ? { ...photo, is_shown: shown } : photo)) })) } : data
  const showPhoto = useMutation({
    mutationFn: ({ id, shown }: { id: number; shown: boolean }) => api.put<Data<Review>>(`admin/review-photos/${id}`, { is_shown: shown }),
    onMutate: ({ id, shown }) => client.setQueryData<Data<Review[]>>(['reviews', 'pending'], (data) => setShown(data, id, shown)),
    onError: (_error, { id, shown }) => {
      client.setQueryData<Data<Review[]>>(['reviews', 'pending'], (data) => setShown(data, id, !shown))
      toast(t('reviews.photoNotSaved'), 'error')
    },
    onSettled: refresh,
  })

  const reviews = pending.data?.data ?? []
  if (reviews.length === 0) return null

  return (
    <Card>
      <CardTitle title={t('reviews.pendingTitle', { n: number(reviews.length) })} aside={<Badge tone="orange">{t('reviews.pendingBadge')}</Badge>} />
      <p className="m-0 text-13 text-app-muted">{t('reviews.pendingNote')}</p>
      {approve.error ? <ErrorNotice error={approve.error} /> : null}
      <ul className="m-0 flex list-none flex-col gap-3 p-0" data-testid="pending-reviews">
        {reviews.map((review) => (
          <li key={review.id} className="flex flex-col gap-2.5 rounded-12 border border-app-line bg-app-surface-2 p-3.5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span className="text-15 font-semibold">{review.reviewer_name}</span>
              <span aria-label={t('reviews.stars', { n: number(review.rating) })} className="text-14 text-orange">
                {'★'.repeat(review.rating)}
                <span className="text-app-line">{'★'.repeat(5 - review.rating)}</span>
              </span>
              {review.booking ? (
                <Link to={`/bookings/${review.booking.id}`} className="text-12">
                  <Badge tone="green">✓ {t('reviews.travelled', { reference: review.booking.reference })}</Badge>
                </Link>
              ) : (
                <Badge tone="slate">{t('reviews.noBooking')}</Badge>
              )}
            </div>
            <span className="text-12 text-app-muted">
              {[review.phone ? digits(`0${review.phone.slice(3)}`) : null, review.trip_label_en ?? review.trip_label_bn, review.travelled_on ? month(review.travelled_on.slice(0, 7)) : null, review.submitted_at ? t('reviews.sentAt', { at: dateTime(review.submitted_at) }) : null]
                .filter(Boolean)
                .join(' · ')}
            </span>
            <p className="m-0 text-14 leading-1.6 whitespace-pre-line">{review.quote_bn}</p>
            {review.photos.length > 0 ? (
              <ul className="m-0 flex list-none flex-wrap gap-2.5 p-0">
                {review.photos.map((photo, index) => (
                  <li key={photo.id} className="flex flex-col items-center gap-1">
                    <button type="button" onClick={() => setEnlarged(photo.image?.variants.detail?.url ?? photo.image?.url ?? null)} className={`cursor-pointer ${photo.is_shown ? '' : 'opacity-40'}`} aria-label={t('reviews.enlargePhoto', { n: number(index + 1) })}>
                      <img src={photo.image?.variants.thumb?.url ?? photo.image?.url ?? undefined} alt="" className="size-20 rounded-10 object-cover" />
                    </button>
                    <label className="flex cursor-pointer items-center gap-1 text-11 text-app-muted">
                      <input type="checkbox" checked={photo.is_shown} onChange={(event) => showPhoto.mutate({ id: photo.id, shown: event.target.checked })} />
                      {t('reviews.showPhoto')}
                    </label>
                  </li>
                ))}
              </ul>
            ) : null}
            <div className="flex flex-wrap gap-2">
              <button type="button" className={buttonClass('primary', 'sm')} disabled={approve.isPending} onClick={() => approve.mutate(review.id)}>
                ✓ {t('reviews.approve')}
              </button>
              <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setRejecting(review)}>
                {t('reviews.reject')}
              </button>
            </div>
          </li>
        ))}
      </ul>
      {rejecting ? <RejectDialog review={rejecting} onDone={refresh} onClose={() => setRejecting(null)} /> : null}
      {enlarged ? (
        <Dialog open onClose={() => setEnlarged(null)} title={t('reviews.photo')}>
          <img src={enlarged} alt="" className="max-h-[70vh] w-full rounded-10 object-contain" />
        </Dialog>
      ) : null}
    </Card>
  )
}

function RejectDialog({ review, onDone, onClose }: { review: Review; onDone: () => void; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reject = useMutation({
    mutationFn: () => api.post<Data<Review>>(`admin/reviews/${review.id}/reject`, { reason: reason.trim() || null }),
    onSuccess: () => {
      onDone()
      toast(t('reviews.rejected'))
      onClose()
    },
  })

  return (
    <Dialog open onClose={onClose} title={t('reviews.rejectTitle', { name: review.reviewer_name })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('reviews.rejectNote')}</p>
      <TextArea label={t('reviews.rejectReason')} value={reason} onChange={setReason} rows={3} maxLength={300} />
      {reject.error ? <ErrorNotice error={reject.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('danger')} disabled={reject.isPending} onClick={() => reject.mutate()}>
          {t('reviews.reject')}
        </button>
      </div>
    </Dialog>
  )
}
