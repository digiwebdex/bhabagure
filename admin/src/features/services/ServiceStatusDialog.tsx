import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { useUpdateService, type ServiceFields, type ServiceStatus } from './api'

/**
 * A ticket's or a hotel booking's status, and what comes with it: a PNR that has come through, new dates, and for a
 * flight whose schedule changed, what the new schedule is — the note the customer's WhatsApp message carries.
 */
export function ServiceStatusDialog({ record, onClose }: { record: ServiceFields & { id: number }; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const update = useUpdateService()
  const air = record.type === 'air_ticket'
  const oneWay = air && record.trip_type === 'one_way'
  const [status, setStatus] = useState<ServiceStatus>(record.status ?? 'booked')
  const [reference, setReference] = useState(record.reference ?? '')
  const [startsOn, setStartsOn] = useState(record.starts_on ?? '')
  const [endsOn, setEndsOn] = useState(record.ends_on ?? '')
  const [note, setNote] = useState(record.schedule_note ?? '')
  const error = (field: string) => (update.error instanceof ApiError ? update.error.field(field) : undefined)
  const statuses: ServiceStatus[] = air ? ['booked', 'confirmed', 'schedule_changed'] : ['booked', 'confirmed']

  return (
    <Dialog open onClose={onClose} title={air ? t('serviceRecords.updateTicket') : t('serviceRecords.updateBooking')}>
      <form
        className="flex flex-col gap-3"
        data-testid="service-status-form"
        onSubmit={(event) => {
          event.preventDefault()
          if (update.isPending) return
          update.mutate(
            { id: record.id, input: { status, reference: reference.trim() || null, starts_on: startsOn, ends_on: oneWay ? null : endsOn || null, schedule_note: status === 'schedule_changed' ? note : null } },
            {
              onSuccess: () => {
                toast(t('common.saved'))
                onClose()
              },
            },
          )
        }}
      >
        <SelectInput label={air ? t('serviceRecords.fields.ticketStatus') : t('serviceRecords.fields.bookingStatus')} value={status} onChange={(value) => setStatus(value as ServiceStatus)} options={statuses.map((value) => ({ value, label: t(`serviceRecords.statuses.${value}`) }))} error={error('status')} />
        {status === 'schedule_changed' ? (
          <TextArea label={t('serviceRecords.fields.scheduleNote')} value={note} onChange={setNote} rows={3} error={error('schedule_note')} hint={t('serviceRecords.fields.scheduleNoteHint')} autoFocus />
        ) : null}
        <TextInput label={air ? t('serviceRecords.fields.pnr') : t('serviceRecords.fields.reservation')} value={reference} onChange={setReference} error={error('reference')} />
        <Pair>
          <TextInput label={air ? t('serviceRecords.fields.departure') : t('serviceRecords.fields.checkIn')} type="date" value={startsOn} onChange={setStartsOn} error={error('starts_on')} />
          {!oneWay ? <TextInput label={air ? t('serviceRecords.fields.return') : t('serviceRecords.fields.checkOut')} type="date" value={endsOn} onChange={setEndsOn} error={error('ends_on')} /> : <span />}
        </Pair>
        {update.error && !(update.error instanceof ApiError && update.error.status === 422) ? <ErrorNotice error={update.error} /> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={onClose}>
            {t('common.cancel')}
          </button>
          <button type="submit" className={buttonClass('primary')} aria-disabled={update.isPending}>
            {update.isPending ? t('common.saving') : t('common.save')}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
