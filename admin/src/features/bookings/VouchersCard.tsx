import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { Badge, Card, CardTitle } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { fileSize, useVoucherFile } from '../vouchers/api'
import { UploadVoucherDialog } from '../vouchers/VoucherDialogs'
import type { BookingDetail } from './api'

/**
 * Suppliers' confirmation vouchers for this booking (docs/booking-vouchers.md), soonest service date first. Hidden from
 * staff who may not see vouchers; uploading from here fills in the booking number.
 */
export function VouchersCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const file = useVoucherFile()
  const [uploading, setUploading] = useState(false)
  if (booking.vouchers === null) return null

  return (
    <Card>
      <CardTitle
        title={t('vouchers.cardTitle')}
        aside={
          booking.actions.upload_voucher ? (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setUploading(true)}>
              {t('vouchers.add')}
            </button>
          ) : null
        }
      />
      {booking.vouchers.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('vouchers.none')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="booking-vouchers">
          {booking.vouchers.map((voucher) => (
            <li key={voucher.id} className="flex flex-col gap-0.5 rounded-10 bg-app-surface-2 px-3 py-2 text-13">
              <span className="flex flex-wrap items-center gap-1.5 font-medium">
                {voucher.title}
                <Badge tone={voucher.mime === 'application/pdf' ? 'red' : 'blue'}>{voucher.mime === 'application/pdf' ? 'PDF' : 'JPG'}</Badge>
              </span>
              <span className="text-12 text-app-muted">
                {[voucher.service_date ? date(voucher.service_date) : t('vouchers.noDate'), fileSize(voucher.bytes), voucher.uploaded_by].filter(Boolean).join(' · ')}
              </span>
              <span className="flex flex-wrap gap-3 text-12">
                <button type="button" className="cursor-pointer font-semibold text-blue" onClick={() => void file.open(voucher)} aria-label={t('vouchers.openNamed', { title: voucher.title })}>
                  {t('vouchers.open')}
                </button>
                <button type="button" className="cursor-pointer font-semibold text-blue" onClick={() => void file.download(voucher)} aria-label={t('vouchers.downloadNamed', { title: voucher.title })}>
                  {t('vouchers.download')}
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
      <Link to={`/vouchers?search=${encodeURIComponent(booking.reference)}`} className="text-12">
        {t('vouchers.allForBooking')}
      </Link>
      {uploading ? <UploadVoucherDialog bookingReference={booking.reference} onClose={() => setUploading(false)} /> : null}
    </Card>
  )
}
