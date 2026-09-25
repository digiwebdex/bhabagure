import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useArchiveVoucher, useUploadVoucher, VOUCHER_MAX_BYTES, type Voucher } from './api'

/**
 * Upload a confirmation voucher or contract (docs/booking-vouchers.md): a title, the PDF or JPG, and optionally the booking
 * and the service date. From a booking's page the booking number is filled in and fixed.
 */
export function UploadVoucherDialog({ bookingReference, onClose }: { bookingReference?: string; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({ title: '', booking_reference: bookingReference ?? '', service_date: '' })
  const [file, setFile] = useState<File | null>(null)
  const save = useUploadVoucher()
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const tooBig = file !== null && file.size > VOUCHER_MAX_BYTES
  const ready = form.title.trim() !== '' && file !== null && !tooBig

  return (
    <Dialog open onClose={onClose} title={t('vouchers.uploadTitle')}>
      <TextInput label={t('vouchers.titleField')} value={form.title} onChange={(title) => setForm({ ...form, title })} error={fieldError('title')} hint={t('vouchers.titleHint')} maxLength={160} />
      <Pair>
        <TextInput
          label={t('vouchers.booking')}
          value={form.booking_reference}
          onChange={(booking_reference) => setForm({ ...form, booking_reference: booking_reference.toUpperCase() })}
          error={fieldError('booking_reference')}
          hint={t('vouchers.bookingHint')}
          placeholder="BH-2609-001"
          autoComplete="off"
          readOnly={bookingReference !== undefined}
        />
        <TextInput label={t('vouchers.serviceDate')} type="date" value={form.service_date} onChange={(service_date) => setForm({ ...form, service_date })} error={fieldError('service_date')} hint={t('vouchers.serviceDateHint')} />
      </Pair>
      <label className="flex flex-col gap-1.25 text-13">
        <span className="text-app-muted">{t('vouchers.file')}</span>
        <input type="file" accept="application/pdf,image/jpeg,.pdf,.jpg,.jpeg" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        <span className={`text-12 ${tooBig || fieldError('file') ? 'font-semibold text-red' : 'text-app-muted'}`}>{tooBig ? t('vouchers.tooBig') : (fieldError('file') ?? t('vouchers.fileHint'))}</span>
      </label>
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || save.isPending}
          onClick={() => file && save.mutate({ ...form, file }, { onSuccess: () => { toast(t('vouchers.uploaded')); onClose() } })}
        >
          {save.isPending ? t('common.working') : t('vouchers.upload')}
        </button>
      </div>
    </Dialog>
  )
}

export function ArchiveVoucherDialog({ voucher, onClose }: { voucher: Voucher; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const archive = useArchiveVoucher()

  return (
    <Dialog open onClose={onClose} title={t('vouchers.archiveTitle', { title: voucher.title })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('vouchers.archiveNote')}</p>
      <TextArea label={t('vouchers.archiveReason')} value={reason} onChange={setReason} rows={3} maxLength={300} />
      {archive.error ? <ErrorNotice error={archive.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || archive.isPending}
          onClick={() => archive.mutate({ id: voucher.id, reason: reason.trim() }, { onSuccess: () => { toast(t('vouchers.archived')); onClose() } })}
        >
          {t('vouchers.archive')}
        </button>
      </div>
    </Dialog>
  )
}
