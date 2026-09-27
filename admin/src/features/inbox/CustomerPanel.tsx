import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { Badge } from '../../components/ui/layout'
import { api, ApiError } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { useCreateLead, useLinkCustomer, type ConversationDetail } from './api'

type CustomerHit = { id: number; name: string; phone: string }

/**
 * Who the chat is with (the design's "Create lead" / "Start booking"): the linked customer and their latest bookings,
 * or a lead made from the chat, or an existing customer picked by name or number.
 */
export function CustomerPanel({ conversation }: { conversation: ConversationDetail }) {
  const { t } = useTranslation()
  const { bdt, date, digits } = useFormat()
  const { can } = useAuth()
  const link = useLinkCustomer(conversation.id)
  const customer = conversation.customer

  return (
    <aside className="flex min-w-0 flex-col gap-3 rounded-14 border border-app-line bg-app-surface p-4 min-[1180px]:overflow-y-auto" aria-label={t('inbox.customer')} data-testid="customer-panel">
      <h3 className="m-0 text-14 font-bold">{t('inbox.customer')}</h3>
      {customer === null ? (
        can('inbox.reply') ? <NoCustomer conversation={conversation} /> : <p className="m-0 text-13 text-app-muted">{t('inbox.notLinked')}</p>
      ) : !customer.visible ? (
        <p className="m-0 text-13 text-app-muted">{t('inbox.customerElsewhere')}</p>
      ) : (
        <>
          <div className="flex flex-col gap-0.5">
            <Link to={`/customers/${customer.id}`} className="text-15 font-semibold">
              {customer.name}
            </Link>
            <span className="font-display text-12 text-app-muted">{digits(customer.phone.replace(/^88/, ''))}</span>
            {customer.email ? <span className="truncate text-12 text-app-muted">{customer.email}</span> : null}
          </div>
          <div className="flex flex-wrap gap-2">
            {can('bookings.create') ? (
              <Link to="/bookings/new" state={{ customer: { id: customer.id, name: customer.name, phone: customer.phone } }} className={buttonClass('cta', 'sm')}>
                {t('inbox.startBooking')}
              </Link>
            ) : null}
          </div>
          <div className="flex flex-col gap-1.5">
            <span className="text-12 font-semibold text-app-muted">{t('inbox.bookings')}</span>
            {customer.bookings.length === 0 ? <span className="text-13 text-app-muted">{t('inbox.noBookings')}</span> : null}
            {customer.bookings.map((booking) => (
              <Link key={booking.id} to={`/bookings/${booking.id}`} className="flex flex-col gap-0.5 rounded-10 border border-app-line px-2.5 py-2 text-13 no-underline hover:border-blue">
                <span className="flex items-center justify-between gap-2">
                  <span className="font-display font-semibold">{booking.reference}</span>
                  <Badge tone={booking.status === 'confirmed' ? 'green' : booking.status === 'cancelled' ? 'slate' : 'orange'}>{t(`bookings.status.${booking.status}`, { defaultValue: booking.status })}</Badge>
                </span>
                <span className="truncate text-app-text">{booking.package_title}</span>
                <span className="text-12 text-app-muted">
                  {booking.travel_start ? date(booking.travel_start) : '—'} · {bdt(booking.total)}
                </span>
              </Link>
            ))}
          </div>
          {can('inbox.reply') ? (
            <button type="button" className={buttonClass('ghost', 'sm', 'self-start')} onClick={() => link.mutate(null)}>
              {t('inbox.unlink')}
            </button>
          ) : null}
        </>
      )}
      {link.error ? <ErrorNotice error={link.error} /> : null}
    </aside>
  )
}

function NoCustomer({ conversation }: { conversation: ConversationDetail }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const { can } = useAuth()
  const lead = useCreateLead(conversation.id)
  const link = useLinkCustomer(conversation.id)
  const [name, setName] = useState(conversation.name ?? '')
  const [phone, setPhone] = useState(conversation.phone ? conversation.phone.replace(/^88/, '') : '')
  const [lookup, setLookup] = useState('')
  const hits = useQuery({
    queryKey: ['search', lookup.trim()],
    queryFn: ({ signal }) => api.get<Data<{ customers?: CustomerHit[] }>>(`admin/search?q=${encodeURIComponent(lookup.trim())}`, signal).then((r) => r.data.customers ?? []),
    enabled: lookup.trim().length >= 2,
  })
  const error = (field: string) => (lead.error instanceof ApiError ? lead.error.field(field) : undefined)

  return (
    <div className="flex flex-col gap-3">
      <p className="m-0 text-13 text-app-muted">{t('inbox.notLinked')}</p>
      {can('customers.manage') ? (
        <form
          className="flex flex-col gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            lead.mutate({ name, phone })
          }}
        >
          <TextInput label={t('inbox.leadName')} value={name} onChange={setName} error={error('name')} />
          <TextInput label={t('inbox.leadPhone')} value={phone} onChange={setPhone} error={error('phone')} inputMode="tel" placeholder="01711-000000" hint={conversation.channel === 'messenger' ? t('inbox.askPhone') : undefined} />
          <button type="submit" className={buttonClass('primary', 'sm', 'self-start')} aria-disabled={lead.isPending}>
            {t('inbox.createLead')}
          </button>
        </form>
      ) : null}
      {can('customers.view') ? (
        <div className="flex flex-col gap-1.5">
          <input type="search" className={controlClass()} value={lookup} onChange={(event) => setLookup(event.target.value)} placeholder={t('inbox.findCustomer')} aria-label={t('inbox.findCustomer')} />
          {(hits.data ?? []).map((hit) => (
            <button key={hit.id} type="button" className={buttonClass('outline', 'sm', 'justify-between')} onClick={() => link.mutate(hit.id)}>
              <span className="truncate">{hit.name}</span>
              <span className="font-display text-12 text-app-muted">{digits(hit.phone.replace(/^88/, ''))}</span>
            </button>
          ))}
        </div>
      ) : null}
      {lead.error && !(lead.error instanceof ApiError && lead.error.status === 422) ? <ErrorNotice error={lead.error} /> : null}
      {link.error ? <ErrorNotice error={link.error} /> : null}
    </div>
  )
}
