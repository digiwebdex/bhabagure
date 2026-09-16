import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle } from '../../components/ui/layout'
import { api, ApiError, fetchDocument } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { useBookingAction, type BookingDetail } from './api'

/** Matches BookingTickets::MAX_KB in the API. */
const MAX_BYTES = 5 * 1024 * 1024

/**
 * E-tickets per traveller (docs/phase-6-customer-portal.md §8): recorded here, shown to the customer in the portal and
 * counted in the trip's readiness. A wrong one is voided with a reason; it stays listed, struck through.
 */
export function TicketsCard({ booking }: { booking: BookingDetail }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const toast = useToast()
  const [adding, setAdding] = useState(false)
  const [voiding, setVoiding] = useState<BookingDetail['tickets'][number] | null>(null)
  const travellerName = (id: number) => booking.travellers.find((traveller) => traveller.id === id)?.full_name ?? '—'

  const open = async (id: number) => {
    const tab = window.open('', '_blank')
    try {
      const url = URL.createObjectURL(await fetchDocument(`admin/booking-tickets/${id}/file`))
      if (tab) tab.location.href = url
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      tab?.close()
      toast(t('tickets.openFailed'), 'error')
    }
  }

  return (
    <Card>
      <CardTitle
        title="E-tickets"
        aside={
          booking.actions.manage_tickets ? (
            <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setAdding(true)}>
              {t('tickets.add')}
            </button>
          ) : null
        }
      />
      {booking.tickets.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('tickets.none')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="booking-tickets">
          {booking.tickets.map((ticket) => (
            <li key={ticket.id} className={`flex flex-col gap-0.5 rounded-10 bg-app-surface-2 px-3 py-2 text-13 ${ticket.voidedAt ? 'opacity-60' : ''}`}>
              <span className="flex flex-wrap items-center gap-1.5 font-medium">
                {travellerName(ticket.travellerId)}
                {ticket.voidedAt ? <Badge tone="red">{t('tickets.void')}</Badge> : <Badge tone="green">{t('tickets.issued')}</Badge>}
              </span>
              <span className={`font-display text-12 ${ticket.voidedAt ? 'line-through' : ''}`}>
                {ticket.airline} · PNR {ticket.pnr} · {ticket.ticketNumber}
                {ticket.route ? ` · ${ticket.route}` : ''}
                {ticket.departsOn ? ` · ${date(ticket.departsOn)}` : ''}
              </span>
              {ticket.voidedAt ? <span className="text-12 text-red">{t('tickets.voidedBecause', { name: ticket.voidedBy ?? '—', reason: ticket.voidReason ?? '' })}</span> : null}
              <span className="flex flex-wrap gap-3 text-12">
                {ticket.hasFile ? (
                  <button type="button" className="cursor-pointer font-semibold text-blue" onClick={() => void open(ticket.id)} aria-label={t('tickets.openNamed', { number: ticket.ticketNumber })}>
                    {t('tickets.open')}
                  </button>
                ) : null}
                {booking.actions.manage_tickets && !ticket.voidedAt ? (
                  <button type="button" className="cursor-pointer font-semibold text-red" onClick={() => setVoiding(ticket)} aria-label={t('tickets.voidNamed', { number: ticket.ticketNumber })}>
                    {t('tickets.voidAction')}
                  </button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
      {adding ? <AddTicketDialog booking={booking} onClose={() => setAdding(false)} /> : null}
      {voiding ? <VoidTicketDialog booking={booking} ticket={voiding} onClose={() => setVoiding(null)} /> : null}
    </Card>
  )
}

function AddTicketDialog({ booking, onClose }: { booking: BookingDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [form, setForm] = useState({ traveller: String(booking.travellers[0]?.id ?? ''), airline: '', pnr: '', ticketNumber: '', route: '', departsOn: booking.travel_start ?? '' })
  const [file, setFile] = useState<File | null>(null)
  const save = useBookingAction(booking.id, () => {
    const body = new FormData()
    body.append('booking_traveller_id', form.traveller)
    body.append('airline', form.airline.trim())
    body.append('pnr', form.pnr.trim())
    body.append('ticket_number', form.ticketNumber.trim())
    if (form.route.trim()) body.append('route', form.route.trim())
    if (form.departsOn) body.append('departs_on', form.departsOn)
    if (file) body.append('file', file)
    return api.post<Data<BookingDetail>>(`admin/bookings/${booking.id}/tickets`, body)
  })
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const tooBig = file !== null && file.size > MAX_BYTES
  const ready = form.traveller !== '' && form.airline.trim() !== '' && form.pnr.trim() !== '' && form.ticketNumber.trim() !== '' && !tooBig

  return (
    <Dialog open onClose={onClose} title={t('tickets.addTitle')}>
      <SelectInput
        label={t('tickets.traveller')}
        value={form.traveller}
        onChange={(traveller) => setForm({ ...form, traveller })}
        options={booking.travellers.map((traveller) => ({ value: String(traveller.id), label: traveller.full_name }))}
        error={fieldError('booking_traveller_id')}
      />
      <Pair>
        <TextInput label={t('tickets.airline')} value={form.airline} onChange={(airline) => setForm({ ...form, airline })} error={fieldError('airline')} maxLength={80} />
        <TextInput label={t('tickets.pnr')} value={form.pnr} onChange={(pnr) => setForm({ ...form, pnr: pnr.toUpperCase() })} error={fieldError('pnr')} maxLength={12} />
      </Pair>
      <Pair>
        <TextInput label={t('tickets.ticketNumber')} value={form.ticketNumber} onChange={(ticketNumber) => setForm({ ...form, ticketNumber })} error={fieldError('ticket_number')} hint={t('tickets.ticketNumberHint')} maxLength={20} />
        <TextInput label={t('tickets.departsOn')} type="date" value={form.departsOn} onChange={(departsOn) => setForm({ ...form, departsOn })} error={fieldError('departs_on')} />
      </Pair>
      <TextInput label={t('tickets.route')} value={form.route} onChange={(route) => setForm({ ...form, route })} error={fieldError('route')} hint={t('tickets.routeHint')} maxLength={80} />
      <label className="flex flex-col gap-1.25 text-13">
        <span className="font-semibold">{t('tickets.file')}</span>
        <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        <span className={`text-12 ${tooBig ? 'text-red' : 'text-app-muted'}`}>{tooBig ? t('tickets.tooBig') : t('tickets.fileHint')}</span>
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
          onClick={() => save.mutate(undefined, { onSuccess: () => { toast(t('tickets.added')); onClose() } })}
        >
          {t('tickets.save')}
        </button>
      </div>
    </Dialog>
  )
}

function VoidTicketDialog({ booking, ticket, onClose }: { booking: BookingDetail; ticket: BookingDetail['tickets'][number]; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const voidTicket = useBookingAction(booking.id, () => api.post<Data<BookingDetail>>(`admin/booking-tickets/${ticket.id}/void`, { reason: reason.trim() }))

  return (
    <Dialog open onClose={onClose} title={t('tickets.voidTitle', { number: ticket.ticketNumber })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('tickets.voidNote')}</p>
      <TextArea label={t('tickets.reason')} value={reason} onChange={setReason} rows={3} maxLength={300} />
      {voidTicket.error ? <ErrorNotice error={voidTicket.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || voidTicket.isPending}
          onClick={() => voidTicket.mutate(undefined, { onSuccess: () => { toast(t('tickets.voided')); onClose() } })}
        >
          {t('tickets.voidAction')}
        </button>
      </div>
    </Dialog>
  )
}
