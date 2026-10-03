import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { useFormat } from '../../lib/useFormat'
import { Dropdown } from '../invoices/ItemPicker'
import { useDismiss } from '../invoices/useDismiss'
import { useVoucherBookings, type VoucherBookingHit } from './api'

/**
 * The voucher windows' booking box (2026-10-03): clicking it lists the newest bookings this staff member may see, typing a
 * number, name or phone narrows the list, and picking one fills in its number. A number typed in full works too (the API
 * checks it), and emptying the box leaves the voucher without a booking.
 */
export function BookingFinder({ label, hint, value, onChange, error, readOnly = false }: {
  label: string
  hint: string
  value: string
  onChange: (reference: string) => void
  error?: string
  readOnly?: boolean
}) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const id = useId()
  const [open, setOpen] = useState(false)
  const [picked, setPicked] = useState<VoucherBookingHit | null>(null)
  const box = useRef<HTMLDivElement>(null)
  useDismiss(box, open, () => setOpen(false))
  const bookings = useVoucherBookings(value, open && !readOnly)
  const describe = (booking: VoucherBookingHit) => [booking.customer, booking.title, booking.travel_start ? date(booking.travel_start) : null].filter(Boolean).join(' · ')
  const shown = picked && picked.reference === value ? describe(picked) : null

  return (
    <div ref={box} className="relative flex min-w-0 flex-col gap-1.25">
      <label htmlFor={id} className="text-13 text-app-muted">
        {label}
      </label>
      <input
        id={id}
        type="search"
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${id}-list`}
        aria-invalid={!!error}
        aria-describedby={`${id}-hint`}
        value={value}
        placeholder="BH-2609-001"
        readOnly={readOnly}
        onFocus={() => !readOnly && setOpen(true)}
        onClick={() => !readOnly && setOpen(true)}
        onChange={(event) => {
          onChange(event.target.value.toUpperCase())
          setOpen(true)
        }}
        className={controlClass(!!error)}
      />
      <span id={`${id}-hint`} className={`text-12 ${error ? 'font-semibold text-red' : 'text-app-muted'}`}>
        {error ?? shown ?? hint}
      </span>
      {open ? (
        <Dropdown testId="voucher-booking-options">
          <div id={`${id}-list`} role="listbox" aria-label={t('vouchers.bookings')} className="max-h-72 overflow-y-auto">
            {bookings.isPending ? <p className="m-0 p-2 text-13 text-app-muted">{t('common.loading')}</p> : null}
            {bookings.isError ? <ErrorNotice error={bookings.error} /> : null}
            {bookings.data && bookings.data.data.length === 0 ? <p className="m-0 p-2 text-13 text-app-muted">{t('vouchers.noBookingFound')}</p> : null}
            {(bookings.data?.data ?? []).map((booking) => (
              <button
                key={booking.id}
                type="button"
                role="option"
                aria-selected={booking.reference === value}
                onClick={() => {
                  onChange(booking.reference)
                  setPicked(booking)
                  setOpen(false)
                }}
                className="flex w-full cursor-pointer flex-col items-start gap-0.5 rounded-8 px-2 py-2 text-left hover:bg-app-surface-2"
              >
                <span className="font-display text-13 font-semibold">{booking.reference}</span>
                <span className="w-full truncate text-12 text-app-muted">{describe(booking)}</span>
              </button>
            ))}
          </div>
        </Dropdown>
      ) : null}
    </div>
  )
}
