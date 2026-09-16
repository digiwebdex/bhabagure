import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'

import { buttonClass } from '../../components/ui/button'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { TextArea } from '../../components/ui/fields'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { supportActions, ticketTone, useSupportAction, useSupportTicket, type SupportTicketDetail } from './api'

export function SupportTicketPage() {
  const { id } = useParams()
  const { t } = useTranslation()
  const ticket = useSupportTicket(Number(id))

  if (ticket.isPending) return <Loading />
  if (ticket.isError) return ticket.error instanceof ApiError && ticket.error.status === 404 ? <EmptyState title={t('errors.notFoundTitle')} /> : <ErrorNotice error={ticket.error} />

  return <Ticket ticket={ticket.data.data} />
}

/** One ticket: the conversation, a reply that goes to the customer by WhatsApp and email, and closing it. */
function Ticket({ ticket }: { ticket: SupportTicketDetail }) {
  const { t } = useTranslation()
  const { dateTime, digits, number } = useFormat()
  const toast = useToast()
  const [body, setBody] = useState('')
  const reply = useSupportAction(ticket.id, supportActions.reply(ticket.id))
  const close = useSupportAction(ticket.id, supportActions.close(ticket.id))

  return (
    <>
      <PageHeader
        title={ticket.subject}
        subtitle={`${ticket.number}${ticket.booking ? ` · ${ticket.booking.reference}` : ''}`}
        actions={
          <>
            <Link to="/support" className={buttonClass('outline', 'sm')}>
              {t('support.back')}
            </Link>
            {ticket.status !== 'closed' ? (
              <button type="button" className={buttonClass('outline', 'sm')} disabled={close.isPending} onClick={() => close.mutate(undefined, { onSuccess: () => toast(t('support.closed')) })}>
                {t('support.close')}
              </button>
            ) : null}
          </>
        }
      />

      <div className="grid-auto-fit-half-320 grid items-start gap-4.5">
        <Card>
          <CardTitle title="Conversation" aside={<Badge tone={ticketTone(ticket)}>{ticket.overdue ? t('support.overdue') : t(`support.status.${ticket.status}`)}</Badge>} />
          <ol className="m-0 flex list-none flex-col gap-2.5 p-0" data-testid="support-messages">
            {ticket.messages.map((message) => (
              <li
                key={message.id}
                className={`flex max-w-[88%] flex-col gap-1 rounded-12 px-3.5 py-2.5 text-14 leading-1.55 ${
                  message.author === 'customer' ? 'self-start border border-app-line bg-app-surface-2' : 'self-end bg-blue-tint text-blue-ink'
                }`}
              >
                <span className="text-11 font-semibold opacity-75">
                  {message.author === 'customer' ? ticket.customer.name : (message.staff?.name ?? t('support.staff'))} · {dateTime(message.created_at)}
                </span>
                <span className="break-words whitespace-pre-wrap">{message.body}</span>
              </li>
            ))}
          </ol>
          <TextArea label={t('support.reply')} value={body} onChange={setBody} rows={4} maxLength={4000} hint={t('support.replyHint', { n: number(body.length) })} />
          {reply.error ? <ErrorNotice error={reply.error} /> : null}
          <button
            type="button"
            className={buttonClass('primary', 'md', 'self-start')}
            disabled={body.trim().length < 2 || reply.isPending}
            onClick={() => reply.mutate(body.trim(), { onSuccess: () => { setBody(''); toast(t('support.replied')) } })}
          >
            {t('support.sendReply')}
          </button>
        </Card>

        <Card>
          <CardTitle title="Customer" />
          <div className="flex flex-col gap-1 text-13">
            <Link to={`/customers/${ticket.customer.id}`} className="text-14 font-semibold">
              {ticket.customer.name}
            </Link>
            <span className="font-display text-app-muted">{digits(ticket.customer.phone.replace(/^88/, ''))}</span>
            {ticket.booking ? (
              <Link to={`/bookings/${ticket.booking.id}`} className="font-display">
                {ticket.booking.reference}
                {ticket.booking.assigned_staff ? ` · ${ticket.booking.assigned_staff.name}` : ''}
              </Link>
            ) : null}
          </div>
          <dl className="m-0 grid gap-1.5 text-13">
            <div className="flex justify-between gap-3">
              <dt className="text-app-muted">{t('support.opened')}</dt>
              <dd className="m-0">{dateTime(ticket.created_at)}</dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-app-muted">{t('support.lastCustomer')}</dt>
              <dd className="m-0">{dateTime(ticket.last_customer_message_at)}</dd>
            </div>
            {ticket.last_staff_reply_at ? (
              <div className="flex justify-between gap-3">
                <dt className="text-app-muted">{t('support.lastReply')}</dt>
                <dd className="m-0">{dateTime(ticket.last_staff_reply_at)}</dd>
              </div>
            ) : null}
          </dl>
        </Card>
      </div>
    </>
  )
}
