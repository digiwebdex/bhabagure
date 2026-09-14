import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextArea } from '../../components/ui/fields'
import { useReviewDocument } from './api'

/** Verify now, or reject with the reason the customer will read in the portal. */
export function RejectDocumentDialog({ id, name, onClose }: { id: number | null; name: string; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const review = useReviewDocument()
  const [reason, setReason] = useState('')

  return (
    <Dialog open={id !== null} onClose={onClose} title={t('documents.rejectTitle', { name })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('documents.rejectNote')}</p>
      <TextArea label={t('documents.reason')} value={reason} onChange={setReason} rows={3} maxLength={300} />
      {review.error ? <ErrorNotice error={review.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || review.isPending || id === null}
          onClick={() =>
            id !== null &&
            review.mutate(
              { id, decision: 'rejected', reason: reason.trim() },
              {
                onSuccess: () => {
                  toast(t('documents.rejected'))
                  setReason('')
                  onClose()
                },
              },
            )
          }
        >
          {t('documents.reject')}
        </button>
      </div>
    </Dialog>
  )
}
