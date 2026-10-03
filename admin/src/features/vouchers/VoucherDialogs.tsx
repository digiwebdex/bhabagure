import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { fileKind, fileSize, useArchiveVoucher, useUpdateVoucher, useUploadVoucher, useVoucherFile, VOUCHER_ACCEPT, VOUCHER_MAX_BYTES, type Voucher } from './api'
import { BookingFinder } from './BookingFinder'

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
        <BookingFinder
          label={t('vouchers.booking')}
          value={form.booking_reference}
          onChange={(booking_reference) => setForm({ ...form, booking_reference })}
          error={fieldError('booking_reference')}
          hint={t('vouchers.bookingHint')}
          readOnly={bookingReference !== undefined}
        />
        <TextInput label={t('vouchers.serviceDate')} type="date" value={form.service_date} onChange={(service_date) => setForm({ ...form, service_date })} error={fieldError('service_date')} hint={t('vouchers.serviceDateHint')} />
      </Pair>
      <label className="flex flex-col gap-1.25 text-13">
        <span className="text-app-muted">{t('vouchers.file')}</span>
        <input type="file" accept={VOUCHER_ACCEPT} onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
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

/**
 * Edit a voucher (docs/booking-vouchers.md §6, 2026-10-03): title, booking, service date, and optionally a new file. The
 * current file stays unless one is chosen; a replaced file is kept and listed under "Earlier files", where it still opens.
 */
export function EditVoucherDialog({ voucher, onClose }: { voucher: Voucher; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const { dateTime } = useFormat()
  const files = useVoucherFile()
  const [form, setForm] = useState({ title: voucher.title, booking_reference: voucher.booking?.reference ?? '', service_date: voucher.service_date ?? '' })
  const [file, setFile] = useState<File | null>(null)
  const save = useUpdateVoucher()
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const tooBig = file !== null && file.size > VOUCHER_MAX_BYTES
  const changed = file !== null || form.title.trim() !== voucher.title || form.booking_reference.trim() !== (voucher.booking?.reference ?? '') || form.service_date !== (voucher.service_date ?? '')
  const ready = form.title.trim() !== '' && !tooBig && changed

  return (
    <Dialog open onClose={onClose} title={t('vouchers.editTitle')}>
      <TextInput label={t('vouchers.titleField')} value={form.title} onChange={(title) => setForm({ ...form, title })} error={fieldError('title')} hint={t('vouchers.titleHint')} maxLength={160} />
      <Pair>
        <BookingFinder
          label={t('vouchers.booking')}
          value={form.booking_reference}
          onChange={(booking_reference) => setForm({ ...form, booking_reference })}
          error={fieldError('booking_reference')}
          hint={t('vouchers.bookingEditHint')}
        />
        <TextInput label={t('vouchers.serviceDate')} type="date" value={form.service_date} onChange={(service_date) => setForm({ ...form, service_date })} error={fieldError('service_date')} hint={t('vouchers.serviceDateHint')} />
      </Pair>

      <div className="flex flex-col gap-1.5 rounded-12 border border-app-line bg-app-surface-2 p-3" data-testid="voucher-current-file">
        <span className="text-12 font-semibold text-app-muted">{t('vouchers.currentFile')}</span>
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-13">
          <strong className="font-semibold">{fileKind(voucher.mime)}</strong>
          <span className="break-all">{voucher.original_name}</span>
          <span className="text-app-muted">· {fileSize(voucher.bytes)}</span>
          <button type="button" className="cursor-pointer font-semibold text-blue hover:underline" onClick={() => void files.open(voucher)}>
            {t('vouchers.open')}
          </button>
        </span>
        <label className="mt-1 flex flex-col gap-1.25 text-13">
          <span className="text-app-muted">{t('vouchers.replaceFile')}</span>
          <input type="file" accept={VOUCHER_ACCEPT} onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
          <span className={`text-12 ${tooBig || fieldError('file') ? 'font-semibold text-red' : 'text-app-muted'}`}>
            {tooBig ? t('vouchers.tooBig') : (fieldError('file') ?? (file ? t('vouchers.replaceNote', { name: voucher.original_name }) : t('vouchers.replaceHint')))}
          </span>
        </label>
      </div>

      {voucher.earlier_files.length > 0 ? (
        <div className="flex flex-col gap-1.5" data-testid="voucher-earlier-files">
          <span className="text-12 font-semibold text-app-muted">{t('vouchers.earlierFiles')}</span>
          {voucher.earlier_files.map((earlier) => (
            <span key={earlier.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-13">
              <span className="break-all">{earlier.original_name}</span>
              <span className="text-12 text-app-muted">
                · {fileSize(earlier.bytes)} · {t('vouchers.replacedBy', { name: earlier.replaced_by ?? '—', when: dateTime(earlier.replaced_at) })}
              </span>
              <button type="button" className="cursor-pointer font-semibold text-blue hover:underline" onClick={() => void files.open(voucher, earlier)}>
                {t('vouchers.open')}
              </button>
              <button type="button" className="cursor-pointer font-semibold text-blue hover:underline" onClick={() => void files.download(voucher, earlier)}>
                {t('vouchers.download')}
              </button>
            </span>
          ))}
        </div>
      ) : null}

      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || save.isPending}
          onClick={() => save.mutate({ id: voucher.id, ...form, file }, { onSuccess: () => { toast(t('vouchers.saved')); onClose() } })}
        >
          {save.isPending ? t('common.working') : t('vouchers.saveChanges')}
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
