import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { bookingActions, useBookingAction, type BookingDetail } from './api'

type Traveller = BookingDetail['travellers'][number]

/**
 * Staff complete a traveller's details. A website booking needs only the lead's name and WhatsApp number, so the rest
 * often arrives later by phone or WhatsApp (docs/phase-8-visa-quotes-pricing-downloads.md §2).
 */
export function TravellerEditDialog({ booking, traveller, onClose }: { booking: BookingDetail; traveller: Traveller | null; onClose: () => void }) {
  return (
    <Dialog open={traveller !== null} onClose={onClose} title={traveller ? traveller.full_name : ''}>
      {traveller ? <TravellerForm key={traveller.id} booking={booking} traveller={traveller} onClose={onClose} /> : null}
    </Dialog>
  )
}

function TravellerForm({ booking, traveller, onClose }: { booking: BookingDetail; traveller: Traveller; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const save = useBookingAction(booking.id, bookingActions.traveller())
  const [form, setForm] = useState({
    full_name: traveller.full_name,
    phone: traveller.phone ? traveller.phone.replace(/^88/, '') : '',
    email: traveller.email ?? '',
    passport_number: traveller.passport_number ?? '',
    date_of_birth: traveller.date_of_birth ?? '',
    passport_expiry: traveller.passport_expiry ?? '',
  })
  const set = (key: keyof typeof form) => (value: string) => setForm({ ...form, [key]: value })
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const blankToNull = (value: string) => (value.trim() === '' ? null : value.trim())

  return (
    <form
      className="flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate(
          {
            travellerId: traveller.id,
            full_name: form.full_name,
            phone: blankToNull(form.phone),
            email: blankToNull(form.email),
            passport_number: blankToNull(form.passport_number),
            date_of_birth: blankToNull(form.date_of_birth),
            passport_expiry: blankToNull(form.passport_expiry),
          },
          { onSuccess: () => { onClose(); toast(t('bookings.travellerSaved')) } },
        )
      }}
    >
      <TextInput label={t('bookings.travellerName')} value={form.full_name} onChange={set('full_name')} error={fieldError('full_name')} required maxLength={160} />
      <Pair>
        <TextInput label={traveller.is_lead ? t('bookings.whatsapp') : t('bookings.phoneOptional')} value={form.phone} onChange={set('phone')} error={fieldError('phone')} inputMode="tel" placeholder="01711-000000" required={traveller.is_lead} />
        <TextInput label={t('bookings.emailOptional')} type="email" value={form.email} onChange={set('email')} error={fieldError('email')} />
      </Pair>
      <Pair>
        <TextInput label={t('bookings.passportNumber')} value={form.passport_number} onChange={set('passport_number')} error={fieldError('passport_number')} autoCapitalize="characters" placeholder="BW0912345" />
        <TextInput label={t('bookings.passportExpiry')} type="date" value={form.passport_expiry} onChange={set('passport_expiry')} error={fieldError('passport_expiry')} />
      </Pair>
      <TextInput label={t('bookings.dateOfBirth')} type="date" value={form.date_of_birth} onChange={set('date_of_birth')} error={fieldError('date_of_birth')} />
      <p className="m-0 text-12 text-app-muted">{t('bookings.travellerEditNote')}</p>
      {save.error && !(save.error instanceof ApiError && Object.keys(save.error.errors).length > 0) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="submit" className={buttonClass('primary')} disabled={save.isPending}>
          {t('common.save')}
        </button>
      </div>
    </form>
  )
}
