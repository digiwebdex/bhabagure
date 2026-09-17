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
import { useAuth } from '../../app/auth'
import { BookingStatusBadge, PaymentBadge } from '../bookings/badges'
import { CustomerInvoicesCard } from '../customer-accounts/CustomerInvoicesCard'
import { sendCustomerWhatsApp, setCustomerOptOut } from '../notifications/api'
import { QuotationStatusBadge } from '../quotations/QuotationStatusBadge'
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
  const { can } = useAuth()
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
          {/* What they were invoiced and still owe — money, so only for staff who see payments (§9). */}
          {can('payments.view') ? <CustomerInvoicesCard customerId={customer.id} /> : null}
          <QuotationsCard customer={customer} />
          <BookingsCard customer={customer} />
        </div>
        <div className="flex flex-col gap-admin-gap">
          {customer.enquiries.length > 0 ? <EnquiriesCard customer={customer} /> : null}
          {customer.downloads.length > 0 ? <DownloadsCard customer={customer} /> : null}
          <PortalCard customer={customer} />
          <ContactLogCard customer={customer} />
        </div>
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
      <CardTitle title="Contact details" />
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

/**
 * The customer portal (docs/phase-6-customer-portal.md §3.7): claimed or not, recent sign-ins, turning sign-in off (which
 * ends open sessions at once), a WhatsApp invite with the portal address, and NPS answers after trips.
 */
function PortalCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { dateTime, number } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const access = useCustomerAction(customerActions.portalAccess(customer.id))
  const [inviteOpen, setInviteOpen] = useState(false)
  const [confirmBlock, setConfirmBlock] = useState(false)
  const { portal } = customer

  return (
    <Card>
      <CardTitle
        title="Customer portal"
        aside={<Badge tone={portal.disabled_at ? 'red' : portal.claimed_at ? 'green' : 'slate'}>{portal.disabled_at ? t('portal.blocked') : portal.claimed_at ? t('portal.active') : t('portal.notClaimed')}</Badge>}
      />
      <dl className="m-0 grid gap-1.5 text-13">
        <div className="flex justify-between gap-3">
          <dt className="text-app-muted">{t('portal.claimedAt')}</dt>
          <dd className="m-0">{portal.claimed_at ? dateTime(portal.claimed_at) : '—'}</dd>
        </div>
        <div className="flex justify-between gap-3">
          <dt className="text-app-muted">{t('portal.lastLogin')}</dt>
          <dd className="m-0">{portal.last_login_at ? dateTime(portal.last_login_at) : '—'}</dd>
        </div>
      </dl>
      {portal.sign_ins.length > 0 ? (
        <ul className="m-0 flex list-none flex-col gap-1 p-0 text-12 text-app-muted" data-testid="portal-sign-ins">
          {portal.sign_ins.map((entry, i) => (
            <li key={i}>
              {t(`portal.events.${entry.action.replace(/\./g, '_')}`)} · {dateTime(entry.at)}
              {entry.channel ? ` · ${entry.channel.toUpperCase()}` : ''}
            </li>
          ))}
        </ul>
      ) : null}
      {access.error ? <ErrorNotice error={access.error} /> : null}
      <div className="flex flex-wrap gap-2">
        {can('notifications.send') && !portal.disabled_at ? (
          <button type="button" className={buttonClass('outline', 'sm')} disabled={customer.whatsapp_opted_out} title={customer.whatsapp_opted_out ? t('portal.optedOut') : undefined} onClick={() => setInviteOpen(true)}>
            {t('portal.invite')}
          </button>
        ) : null}
        {portal.actions.block ? (
          portal.disabled_at ? (
            <button type="button" className={buttonClass('outline', 'sm')} disabled={access.isPending} onClick={() => access.mutate(true, { onSuccess: () => toast(t('portal.unblocked')) })}>
              {t('portal.unblock')}
            </button>
          ) : (
            <button type="button" className={buttonClass('danger', 'sm')} onClick={() => setConfirmBlock(true)}>
              {t('portal.block')}
            </button>
          )
        ) : null}
      </div>

      {customer.nps.length > 0 ? (
        <div className="flex flex-col gap-1.5 border-t border-app-line pt-3" data-testid="customer-nps">
          <span className="text-12 font-semibold text-app-muted">{t('portal.nps')}</span>
          {customer.nps.map((answer) => (
            <span key={answer.booking_id} className="flex flex-wrap items-center gap-2 text-13">
              <Badge tone={answer.score >= 9 ? 'green' : answer.score >= 7 ? 'slate' : 'red'}>{number(answer.score)}/{number(10)}</Badge>
              <Link to={`/bookings/${answer.booking_id}`} className="font-display">
                {answer.booking_reference}
              </Link>
              {answer.comment ? <span className="text-app-muted">“{answer.comment}”</span> : null}
            </span>
          ))}
        </div>
      ) : null}

      <Dialog open={confirmBlock} onClose={() => setConfirmBlock(false)} title={t('portal.blockTitle', { name: customer.name })}>
        <p className="m-0 text-13 leading-1.6 text-app-muted">{t('portal.blockNote')}</p>
        <div className="flex justify-end gap-2">
          <button type="button" className={buttonClass('outline')} onClick={() => setConfirmBlock(false)}>
            {t('common.cancel')}
          </button>
          <button type="button" className={buttonClass('danger')} disabled={access.isPending} onClick={() => access.mutate(false, { onSuccess: () => { setConfirmBlock(false); toast(t('portal.blockedToast')) } })}>
            {t('portal.block')}
          </button>
        </div>
      </Dialog>
      {inviteOpen ? <InviteDialog customer={customer} onClose={() => setInviteOpen(false)} /> : null}
    </Card>
  )
}

function InviteDialog({ customer, onClose }: { customer: CustomerDetail; onClose: () => void }) {
  const { t } = useTranslation()
  const { digits, number } = useFormat()
  const toast = useToast()
  const client = useQueryClient()
  const [text, setText] = useState(customer.portal.invite_text)
  const send = useMutation({
    mutationFn: () => sendCustomerWhatsApp({ customer_id: customer.id, text: text.trim() }),
    onSuccess: (response) => {
      void client.invalidateQueries({ queryKey: ['customer', customer.id] })
      toast(t(response.data.status === 'pending' ? 'notifications.queued' : 'notifications.sent'))
      onClose()
    },
  })

  return (
    <Dialog open onClose={onClose} title={t('portal.inviteTitle')}>
      <div className="flex flex-col gap-0.5 rounded-10 bg-app-surface-2 px-3 py-2.5 text-13">
        <span className="text-12 text-app-muted">{t('notifications.to')}</span>
        <strong>
          {customer.name} · <span className="font-display">{digits(customer.phone.replace(/^88/, ''))}</span>
        </strong>
        <span className="text-12 text-app-muted">{t('portal.numberFromRecord')}</span>
      </div>
      <TextArea label={t('notifications.message')} value={text} onChange={setText} rows={5} maxLength={1000} hint={`${number(text.length)} / ${number(1000)}`} />
      {send.error ? <ErrorNotice error={send.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('primary')} disabled={text.trim().length < 2 || send.isPending} onClick={() => send.mutate()}>
          {t('notifications.send')}
        </button>
      </div>
    </Dialog>
  )
}

/** What this person sent through the website: the contact form's message, and air-ticket enquiries (worked on Air ticketing). */
function EnquiriesCard({ customer }: { customer: CustomerDetail }) {
  const { t, i18n } = useTranslation()
  const { date, dateTime, number } = useFormat()

  return (
    <Card>
      <CardTitle title="Website enquiries" />
      <ol className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="customer-enquiries">
        {customer.enquiries.map((enquiry) => (
          <li key={enquiry.id} className="flex flex-col gap-1 border-b border-app-line pb-2.5 last:border-b-0">
            <span className="flex flex-wrap items-baseline justify-between gap-2 text-13">
              <span className="font-semibold">
                {t(`customers.enquiryTypes.${enquiry.type}`)}
                {enquiry.package ? ` · ${i18n.language === 'en' ? enquiry.package.title_en : enquiry.package.title_bn}` : ''}
                {enquiry.route && enquiry.route.length > 0 ? ` · ${enquiry.route.join(' → ')}` : ''}
                {enquiry.hotel ? ` · ${enquiry.hotel.location ?? ''}${enquiry.hotel.check_in ? `, ${date(enquiry.hotel.check_in)}` : ''}${enquiry.hotel.category ? ` · ${t(`hotel.categories.${enquiry.hotel.category}`)}` : ''}` : ''}
                {enquiry.pax ? ` · ${t('customers.enquiryPax', { count: enquiry.pax, n: number(enquiry.pax) })}` : ''}
              </span>
              {enquiry.created_at ? <span className="text-12 text-app-muted">{dateTime(enquiry.created_at)}</span> : null}
            </span>
            {enquiry.message ? <span className="text-14 whitespace-pre-line">{enquiry.message}</span> : null}
            {enquiry.type === 'air_quote' ? (
              <Link to="/air-ticketing" className="self-start text-12">
                {t('customers.openAirTicketing')}
              </Link>
            ) : enquiry.type === 'hotel_quote' ? (
              <Link to={`/hotel-requests?state=${enquiry.status === 'quoted' ? 'quoted' : 'open'}`} className="self-start text-12">
                {t('customers.openHotelRequests')}
              </Link>
            ) : null}
          </li>
        ))}
      </ol>
    </Card>
  )
}

/** What they downloaded from the website (Phase 8 §4.E): the package and the choice in it, or the visa. */
function DownloadsCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { dateTime, number } = useFormat()

  return (
    <Card>
      <CardTitle
        title="Downloads from the website"
        aside={
          can('downloads.view') ? (
            <Link to={`/downloads?search=${encodeURIComponent(customer.phone)}`} className="text-12">
              {t('downloads.openList')}
            </Link>
          ) : undefined
        }
      />
      <ol className="m-0 flex list-none flex-col gap-2 p-0" data-testid="customer-downloads">
        {customer.downloads.map((download) => (
          <li key={download.id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-app-line pb-2 text-13 last:border-b-0">
            <span className="flex min-w-0 flex-col">
              <span className="font-semibold">
                {t(`downloads.kinds.${download.kind}`)} · {download.title}
              </span>
              {download.kind === 'package' ? (
                <span className="text-12 text-app-muted">
                  {[download.hotel_category ? t(`grid.categories.${download.hotel_category}`) : null, download.pax ? t('grid.tier', { count: download.pax, n: number(download.pax) }) : null].filter(Boolean).join(' · ')}
                </span>
              ) : null}
            </span>
            <span className="text-12 text-app-muted">{dateTime(download.created_at)}</span>
          </li>
        ))}
      </ol>
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
      <CardTitle title="Contact log" />
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

function QuotationsCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const { can } = useAuth()
  if (!can('quotations.view_all', 'quotations.view_own')) return null

  return (
    <Card>
      <CardTitle
        title="Quotations"
        aside={
          can('quotations.manage') && customer.lead_state !== 'lost' ? (
            <Link to={`/quotations?customer=${customer.id}`} className={buttonClass('outline', 'sm')}>
              {t('quotations.newFor')}
            </Link>
          ) : null
        }
      />
      {customer.quotations.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('quotations.noneForCustomer')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-2 p-0">
          {customer.quotations.map((quotation) => (
            <li key={quotation.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-app-line pb-2 last:border-b-0">
              <span className="flex flex-col">
                <Link to={`/quotations/${quotation.id}`} className="font-display font-semibold">
                  {quotation.number}
                </Link>
                <span className="text-12 text-app-muted">{quotation.package_title_en || quotation.package_title_bn}</span>
              </span>
              <span className="flex items-center gap-2">
                <span className="font-display text-13 font-semibold">{bdt(quotation.total_amount)}</span>
                <QuotationStatusBadge status={quotation.display_status} />
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function BookingsCard({ customer }: { customer: CustomerDetail }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()

  return (
    <Card>
      <CardTitle title="Bookings" />
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
                  {booking.package_title_en || booking.package_title_bn} · {booking.travel_start ? date(booking.travel_start) : '—'}
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
