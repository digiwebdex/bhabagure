import { useTranslation } from 'react-i18next'

import { SelectInput, TextInput } from '../../components/ui/fields'
import { blankService, SERVICE_TYPES, type ServiceFields, type ServiceStatus, type ServiceType, type TripType } from '../services/api'

/**
 * "What it is for" under an invoice line (docs/invoice-services.md), and the fields that go with the answer. An air ticket
 * or a hotel booking reaches Air ticketing or Hotel requests when the invoice is saved; anything else needs nothing more.
 */
export function LineServiceFields({ value, onChange, disabled, error }: {
  value: ServiceFields | null
  onChange: (service: ServiceFields | null) => void
  disabled?: boolean
  error: (field: string) => string | undefined
}) {
  const { t } = useTranslation()
  const set = (patch: Partial<ServiceFields>) => value && onChange({ ...value, ...patch })
  const text = (field: keyof ServiceFields) => (value?.[field] as string | null | undefined) ?? ''
  const input = (field: keyof ServiceFields, label: string, extra: { type?: string; placeholder?: string } = {}) => (
    <TextInput label={label} value={text(field)} onChange={(next) => set({ [field]: next })} disabled={disabled} error={error(field)} {...extra} />
  )
  const status = () => {
    // Schedule changed is set in Air ticketing, with its note; a ticket already there keeps it through a correction.
    const options: ServiceStatus[] = value?.status === 'schedule_changed' ? ['booked', 'confirmed', 'schedule_changed'] : ['booked', 'confirmed']
    return (
      <SelectInput
        label={value?.type === 'air_ticket' ? t('serviceRecords.fields.ticketStatus') : t('serviceRecords.fields.bookingStatus')}
        value={value?.status ?? 'booked'}
        onChange={(next) => set({ status: next as ServiceStatus })}
        options={options.map((option) => ({ value: option, label: t(`serviceRecords.statuses.${option}`) }))}
        disabled={disabled}
        error={error('status')}
      />
    )
  }

  return (
    <div className="flex flex-col gap-2 rounded-12 bg-app-surface-2 p-3" data-testid="line-service">
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <SelectInput
          label={t('serviceRecords.fields.whatFor')}
          value={value?.type ?? ''}
          onChange={(next) => onChange(next === '' ? null : blankService(next as ServiceType, value))}
          options={[{ value: '', label: t('serviceRecords.types.other') }, ...SERVICE_TYPES.map((type) => ({ value: type, label: t(`serviceRecords.types.${type}`) }))]}
          disabled={disabled}
          error={error('type')}
        />
        {value?.type === 'air_ticket' ? (
          <>
            {input('route_from', t('serviceRecords.fields.from'))}
            {input('route_to', t('serviceRecords.fields.to'))}
            <SelectInput
              label={t('serviceRecords.fields.tripType')}
              value={value.trip_type ?? 'round_trip'}
              onChange={(next) => set({ trip_type: next as TripType, ...(next === 'one_way' ? { ends_on: null } : {}) })}
              options={(['round_trip', 'one_way'] as const).map((option) => ({ value: option, label: t(`serviceRecords.tripTypes.${option}`) }))}
              disabled={disabled}
              error={error('trip_type')}
            />
            {input('reference', t('serviceRecords.fields.pnr'))}
            {input('starts_on', t('serviceRecords.fields.departure'), { type: 'date' })}
            {value.trip_type !== 'one_way' ? input('ends_on', t('serviceRecords.fields.return'), { type: 'date' }) : null}
            {status()}
            {input('passenger_name', t('serviceRecords.fields.passenger'), { placeholder: t('serviceRecords.fields.sameAsCustomer') })}
          </>
        ) : null}
        {value?.type === 'hotel_booking' ? (
          <>
            {input('hotel_name', t('serviceRecords.fields.hotelName'))}
            {input('room_type', t('serviceRecords.fields.roomType'))}
            {input('reference', t('serviceRecords.fields.reservation'))}
            {input('starts_on', t('serviceRecords.fields.checkIn'), { type: 'date' })}
            {input('ends_on', t('serviceRecords.fields.checkOut'), { type: 'date' })}
            {status()}
            {input('passenger_name', t('serviceRecords.fields.guest'), { placeholder: t('serviceRecords.fields.sameAsCustomer') })}
          </>
        ) : null}
        {value?.type === 'tour_package' ? (
          <>
            {input('package_name', t('serviceRecords.fields.packageName'), { placeholder: t('serviceRecords.fields.sameAsLine') })}
            {input('starts_on', t('serviceRecords.fields.departure'), { type: 'date' })}
            {input('ends_on', t('serviceRecords.fields.return'), { type: 'date' })}
          </>
        ) : null}
      </div>
      {value?.type === 'air_ticket' ? <span className="text-12 text-app-muted">{t('serviceRecords.airHint')}</span> : null}
      {value?.type === 'hotel_booking' ? <span className="text-12 text-app-muted">{t('serviceRecords.hotelHint')}</span> : null}
    </div>
  )
}
