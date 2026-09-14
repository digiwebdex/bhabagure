import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { SelectInput, Switch, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { BookingStatusBadge, PaymentBadge } from '../bookings/badges'
import { setCustomerOptOut } from '../notifications/api'
import { CONTACT_CHANNELS, CONTACT_OUTCOMES, customerActions, useCustomer, useCustomerAction, type CustomerDetail } from './api'
import { PassportChip, SourcePill } from './CustomersPage'

export function CustomerProfilePage() {
  const { id } = useParams()
  const { t } = useTranslation()
  const customer = useCustomer(Number(id))

  if (customer.isPending) return <Loading />
  if (customer.isError) return customer.error instanceof ApiError && customer.error.status === 404 ? <EmptyState title={t('errors.notFoundTitle')} /> : <ErrorNotice error={customer.error} />

  return <Profile customer={customer.data.data} />
}

function Profile({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const toast = useToast()
  const claim = useCustomerAction(() => customerActions.claim(customer.id))
  const reopen = useCustomerAction(customerActions.reopen(customer.id))
  const [lostOpen, setLostOpen] = useState(false)
  const [assignOpen, setAssignOpen] = useState(false)

  return (
    <>
      <PageHeader
        title={customer.name}
        subtitle={`${digits(customer.phone.replace(/^88/, ''))}${customer.email ? ` · ${customer.email}` : ''}`}
        actions={
          <>
            <Link to="/customers" className={buttonClass('outline', 'sm')}>
              {t('customers.back')}
            </Link>
            {customer.actions.claim ? (
              <button type="button" className={buttonClass('primary', 'sm')} disabled={claim.isPending} onClick={() => claim.mutate(undefined, { onSuccess: () => toast(t('ownership.claimed')) })}>
                {t('ownership.claim')}
              </button>
            ) : null}
            {customer.actions.assign ? (
              <button type="button" className={buttonClass('outline', 'sm')} onClick={() => setAssignOpen(true)}>
                {t('customers.assign')}
              </button>
            ) : null}
            {customer.actions.mark_lost ? (
              <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setLostOpen(true)}>
                {t('customers.markLost')}
              </button>
            ) : null}
            {customer.lead_state === 'lost' && customer.actions.edit ? (
              <button type="button" className={buttonClass('outline', 'sm')} disabled={reopen.isPending} onClick={() => reopen.mutate(undefined, { onSuccess: () => toast(t('customers.reopened')) })}>
                {t('customers.reopen')}
              </button>
            ) : null}
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <SourcePill source={customer.source} />
        <Badge tone={customer.lead_state === 'lost' ? 'red' : customer.lead_state === 'converted' ? 'green' : 'blue'}>
          {customer.stage === 'customer' ? t('customers.stage.customer') : t(`customers.state.${customer.lead_state}`)}
        </Badge>
        <PassportChip status={customer.passport_status} />
        <span className="text-13 text-app-muted">{customer.assigned_staff ? t('customers.ownedBy', { name: customer.assigned_staff.name }) : t('ownership.poolNote')}</span>
      </div>
      {customer.lost_reason ? (
        <p role="note" className="m-0 rounded-10 border border-red-line bg-red-tint px-3.5 py-2.5 text-13 text-red">
          {t('customers.lostBecause', { reason: customer.lost_reason })}
        </p>
      ) : null}
      {claim.error ? <ErrorNotice error={claim.error} /> : null}

      <div className="grid-auto-fit-360 grid items-start gap-admin-gap">
        <div className="flex flex-col gap-admin-gap">
          <DetailsCard customer={customer} />
          <BookingsCard customer={customer} />
        </div>
        <ContactLogCard customer={customer} />
      </div>

      <LostDialog customer={customer} open={lostOpen} onClose={() => setLostOpen(false)} />
      {assignOpen ? <AssignDialog customer={customer} onClose={() => setAssignOpen(false)} /> : null}
    </>
  )
}

function DetailsCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const toast = useToast()
  const client = useQueryClient()
  const save = useCustomerAction(customerActions.update(customer.id))
  const [form, setForm] = useState({ name: customer.name, email: customer.email ?? '', interest: customer.interest ?? '', address: customer.address ?? '', notes: customer.notes ?? '' })
  const optOut = useMutation({
    mutationFn: (optedOut: boolean) => setCustomerOptOut(customer.id, optedOut),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['customer', customer.id] }),
  })
  const editable = customer.actions.edit
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  return (
    <Card>
      <CardTitle bn="যোগাযোগের তথ্য" en="Contact details" />
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate({ name: form.name, email: form.email || null, interest: form.interest || null, address: form.address || null, notes: form.notes || null }, { onSuccess: () => toast(t('common.saved')) })
        }}
      >
        <div className="grid-auto-fit-200 grid gap-3">
          <TextInput label={t('newBooking.name')} value={form.name} onChange={(name) => setForm({ ...form, name })} disabled={!editable} error={fieldError('name')} />
          <TextInput label={t('newBooking.email')} type="email" value={form.email} onChange={(email) => setForm({ ...form, email })} disabled={!editable} error={fieldError('email')} />
        </div>
        <TextInput label={t('customers.interest')} value={form.interest} onChange={(interest) => setForm({ ...form, interest })} disabled={!editable} hint={t('customers.interestHint')} />
        <TextInput label={t('customers.address')} value={form.address} onChange={(address) => setForm({ ...form, address })} disabled={!editable} />
        <TextArea label={t('customers.notes')} value={form.notes} onChange={(notes) => setForm({ ...form, notes })} disabled={!editable} rows={3} />
        {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
        {editable ? (
          <button type="submit" className={buttonClass('primary', 'md', 'self-start')} disabled={save.isPending}>
            {save.isPending ? t('common.saving') : t('common.save')}
          </button>
        ) : (
          <span className="text-12 text-app-muted">{t('customers.claimFirst')}</span>
        )}
      </form>
      <Switch label={t('notifications.customerWhatsApp')} hint={t('notifications.customerWhatsAppHint')} checked={!customer.whatsapp_opted_out} onChange={(on) => !optOut.isPending && optOut.mutate(!on)} />
    </Card>
  )
}

function ContactLogCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  const toast = useToast()
  const log = useCustomerAction(customerActions.contact(customer.id))
  const blank = { channel: 'call', outcome: 'reached', note: '', next: '' }
  const [form, setForm] = useState(blank)

  return (
    <Card>
      <CardTitle bn="যোগাযোগের লগ" en="Contact log" />
      {customer.actions.log_contact ? (
        <form
          className="flex flex-col gap-3 rounded-10 border border-app-line p-3"
          onSubmit={(event) => {
            event.preventDefault()
            log.mutate(
              { channel: form.channel, outcome: form.outcome, note: form.note || null, next_follow_up_at: form.next ? new Date(form.next).toISOString() : null },
              {
                onSuccess: () => {
                  setForm(blank)
                  toast(t('customers.contactLogged'))
                },
              },
            )
          }}
        >
          <div className="grid-auto-fit-160 grid gap-3">
            <SelectInput label={t('customers.channel')} value={form.channel} onChange={(channel) => setForm({ ...form, channel })} options={CONTACT_CHANNELS.map((value) => ({ value, label: t(`customers.channels.${value}`) }))} />
            <SelectInput label={t('customers.outcome')} value={form.outcome} onChange={(outcome) => setForm({ ...form, outcome })} options={CONTACT_OUTCOMES.map((value) => ({ value, label: t(`customers.outcomes.${value}`) }))} />
          </div>
          <TextArea label={t('customers.note')} value={form.note} onChange={(note) => setForm({ ...form, note })} rows={2} />
          <TextInput label={t('customers.nextFollowUp')} type="datetime-local" value={form.next} onChange={(next) => setForm({ ...form, next })} />
          {log.error ? <ErrorNotice error={log.error} /> : null}
          <button type="submit" className={buttonClass('primary', 'sm', 'self-start')} disabled={log.isPending}>
            {log.isPending ? t('common.saving') : t('customers.logContact')}
          </button>
        </form>
      ) : null}

      {customer.contacts.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('customers.noContacts')}</p>
      ) : (
        <ol className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="contact-log">
          {customer.contacts.map((entry) => (
            <li key={entry.id} className="flex flex-col gap-1 border-b border-app-line pb-2.5 last:border-b-0">
              <span className="flex flex-wrap items-baseline justify-between gap-2 text-13">
                <span className="font-semibold">
                  {t(`customers.channels.${entry.channel}`)} · {t(`customers.outcomes.${entry.outcome}`)}
                </span>
                <span className="text-12 text-app-muted">
                  {dateTime(entry.occurred_at)}
                  {entry.staff ? ` · ${entry.staff.name}` : ''}
                </span>
              </span>
              {entry.note ? <span className="text-14 whitespace-pre-line">{entry.note}</span> : null}
              {entry.next_follow_up_at ? <span className="text-12 text-blue">{t('customers.followUp', { when: dateTime(entry.next_follow_up_at) })}</span> : null}
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}

function BookingsCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { bdt, date, locale } = useFormat()

  return (
    <Card>
      <CardTitle bn="বুকিং" en="Bookings" />
      {customer.bookings.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('customers.noBookings')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0">
          {customer.bookings.map((booking) => (
            <li key={booking.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-app-line pb-2 last:border-b-0">
              <span className="flex flex-col">
                <Link to={`/bookings/${booking.id}`} className="font-display font-semibold">
                  {booking.reference}
                </Link>
                <span className="text-12 text-app-muted">
                  {(locale === 'bn' ? booking.package_title_bn : null) || booking.package_title_en} · {booking.travel_start ? date(booking.travel_start) : '—'}
                </span>
              </span>
              <span className="flex items-center gap-2">
                <span className="font-display text-13 font-semibold">{bdt(booking.total_amount)}</span>
                <BookingStatusBadge status={booking.status} />
                <PaymentBadge status={booking.payment_status} />
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function LostDialog({ customer, open, onClose }: { customer: CustomerDetail; open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const lost = useCustomerAction(customerActions.lost(customer.id))

  return (
    <Dialog open={open} onClose={onClose} title={t('customers.markLostTitle', { name: customer.name })}>
      <TextArea label={t('customers.lostReason')} value={reason} onChange={setReason} rows={3} hint={t('customers.lostReasonHint')} />
      {lost.error ? <ErrorNotice error={lost.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('danger')} disabled={reason.trim().length < 3 || lost.isPending} onClick={() => lost.mutate(reason.trim(), { onSuccess: onClose })}>
          {t('customers.markLost')}
        </button>
      </div>
    </Dialog>
  )
}

function AssignDialog({ customer, onClose }: { customer: CustomerDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const staff = useQuery({ queryKey: ['assignable-staff'], queryFn: ({ signal }) => api.get<Data<{ id: number; name: string; role: string | null }[]>>('admin/assignable-staff', signal).then((r) => r.data) })
  const [staffId, setStaffId] = useState(customer.assigned_staff ? String(customer.assigned_staff.id) : '')
  const [reason, setReason] = useState('')
  const assign = useMutation({
    mutationFn: () => api.post<Data<CustomerDetail>>(`admin/customers/${customer.id}/assign`, { staff_id: staffId ? Number(staffId) : null, reason }),
    onSuccess: (response) => {
      client.setQueryData(['customer', customer.id], response)
      void client.invalidateQueries({ queryKey: ['customers'] })
      onClose()
    },
  })

  return (
    <Dialog open onClose={onClose} title={t('customers.assignTitle', { name: customer.name })}>
      {staff.isPending ? (
        <Loading />
      ) : (
        <SelectInput
          label={t('ownership.owner')}
          value={staffId}
          onChange={setStaffId}
          options={[{ value: '', label: t('ownership.unassigned') }, ...(staff.data ?? []).map((person) => ({ value: String(person.id), label: person.name }))]}
        />
      )}
      <TextArea label={t('customers.assignReason')} value={reason} onChange={setReason} rows={2} hint={t('customers.assignReasonHint')} />
      {assign.error ? <ErrorNotice error={assign.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={reason.trim().length < 3 || assign.isPending} onClick={() => assign.mutate()}>
          {t('customers.assign')}
        </button>
      </div>
    </Dialog>
  )
}
