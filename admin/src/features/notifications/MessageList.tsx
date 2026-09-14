import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Badge } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { CHANNELS, type NotificationChannel, type NotificationGroup, type NotificationMessage } from './api'

const CHIP: Record<NotificationChannel, { className: string; label: string }> = {
  whatsapp: { className: 'bg-whatsapp', label: '✆ WhatsApp' },
  email: { className: 'bg-blue', label: '@ Email' },
  sms: { className: 'bg-purple', label: '✉ SMS' },
}

export function ChannelChip({ channel }: { channel: NotificationChannel }) {
  return <span className={`inline-flex items-center gap-1 rounded-pill px-2 py-0.5 text-10 font-bold whitespace-nowrap text-white ${CHIP[channel].className}`}>{CHIP[channel].label}</span>
}

/**
 * WhatsApp-style ticks: ✓ sent, ✓✓ delivered, blue ✓✓ read. An SMS only ever reaches "submitted": bulksmsbd.net reports
 * acceptance, not delivery, and operators drop some messages silently. Waiting, skipped and failed rows say why.
 */
export function DeliveryStatus({ message }: { message: NotificationMessage }) {
  const { t } = useTranslation()
  const { dateTime } = useFormat()
  switch (message.status) {
    case 'read':
      return <span className="font-display text-12 font-semibold whitespace-nowrap text-blue">✓✓ {t('notifications.status.read')}</span>
    case 'delivered':
      return <span className="font-display text-12 font-semibold whitespace-nowrap text-app-muted">✓✓ {t('notifications.status.delivered')}</span>
    case 'sent':
      return (
        <span className="font-display text-12 font-semibold whitespace-nowrap text-app-muted">
          ✓ {message.channel === 'sms' ? t('notifications.status.submitted') : t('notifications.status.sent')}
        </span>
      )
    case 'failed':
      return <Badge tone="red">✕ {t('notifications.status.failed')}</Badge>
    case 'skipped':
    case 'cancelled':
      return <Badge tone="slate">{t(`notifications.status.${message.status}`)}</Badge>
    default:
      return message.status === 'pending' && message.scheduled_for ? (
        <Badge tone="blue">{t('notifications.scheduledFor', { when: dateTime(message.scheduled_for) })}</Badge>
      ) : (
        <Badge tone="orange">{t(`notifications.status.${message.status}`)}</Badge>
      )
  }
}

/** Why a message is waiting, was skipped or failed — in words, not codes. */
function statusNote(message: NotificationMessage, t: (key: string, options?: Record<string, unknown>) => string): string | null {
  if (message.status === 'skipped' || message.status === 'cancelled') {
    return message.skipped_reason ? t(`notifications.reasons.${message.skipped_reason}`, { defaultValue: message.skipped_reason }) : null
  }
  if (message.status === 'pending' || message.status === 'failed') {
    const code =
      message.last_error
        ?.split(':')[0]
        .replace(/^provider_error_\d+$/, 'provider_error')
        .replace(/^sms_provider_error_\d+$/, 'sms_provider_error')
        .replace(/^sms_provider_code_\d+$/, 'sms_provider_code') ?? null
    return code ? t(`notifications.reasons.${code}`, { defaultValue: message.last_error ?? '' }) : null
  }
  return null
}

/** "2 parts · ৳ ০.৭০" for an SMS; the cost of any sent message in the log. */
function CostNote({ message }: { message: NotificationMessage }) {
  const { t } = useTranslation()
  const { bdt, number } = useFormat()
  if (message.channel !== 'sms' || (message.sms_parts === null && message.cost === null)) return null
  return (
    <span className="font-display text-12 text-app-muted">
      {[message.sms_parts !== null ? t('notifications.parts', { count: message.sms_parts, n: number(message.sms_parts) }) : null, message.cost !== null ? bdt(message.cost) : null]
        .filter(Boolean)
        .join(' · ')}
    </span>
  )
}

export function MessageList({ messages, showRelated = false }: { messages: NotificationMessage[]; showRelated?: boolean }) {
  const { t } = useTranslation()
  const { bdt, dateTime, digits } = useFormat()

  return (
    <ul className="m-0 flex list-none flex-col p-0">
      {messages.map((message) => {
        const note = statusNote(message, t)
        const when = message.sent_at ?? (message.status === 'pending' ? null : message.created_at)
        return (
          <li key={message.id} className="flex flex-col gap-1.5 border-b border-app-line py-2.5 last:border-b-0" data-testid="notification-row">
            <div className="flex items-start justify-between gap-3">
              <span className="flex min-w-0 flex-col gap-1">
                <span className="flex flex-wrap items-center gap-1.5 text-13 font-medium">
                  <ChannelChip channel={message.channel} />
                  {t(`notifications.events.${message.event}`, { defaultValue: message.event })}
                  {message.fallback_of_id ? <Badge tone="orange">{t('notifications.fallback')}</Badge> : null}
                  {message.has_attachment ? <span className="text-12 text-app-muted">· {t('notifications.withInvoice')}</span> : null}
                  {showRelated && message.related?.type === 'booking' ? (
                    <Link to={`/bookings/${message.related.id}`} className="text-12">
                      · {t('notifications.openBooking')}
                    </Link>
                  ) : null}
                </span>
                <span className="truncate font-display text-12 text-app-muted">
                  {[message.to ? digits(message.to) : null, when ? dateTime(when) : null].filter(Boolean).join(' · ')}
                </span>
              </span>
              <span className="flex shrink-0 flex-col items-end gap-1">
                <DeliveryStatus message={message} />
                <span className="font-display text-12 text-app-muted" data-testid="notification-cost">
                  {message.cost === null ? '—' : bdt(message.cost)}
                </span>
              </span>
            </div>
            {note ? <span className="text-12 text-app-muted">{note}</span> : null}
            <MessageBody message={message} />
          </li>
        )
      })}
    </ul>
  )
}

function MessageBody({ message, label }: { message: NotificationMessage; label?: string }) {
  const { t } = useTranslation()
  return (
    <details className="group text-13">
      <summary className="cursor-pointer text-12 text-blue select-none">{label ?? t('notifications.showMessage')}</summary>
      <div className="mt-1.5 flex flex-col gap-1 rounded-10 bg-app-surface-2 px-3 py-2.5">
        {message.title ? <strong className="text-13">{message.title}</strong> : null}
        <p className="m-0 leading-1.55 break-words whitespace-pre-wrap">{message.body || '—'}</p>
      </div>
    </details>
  )
}

/**
 * The booking card: one row per message, with WhatsApp, email and SMS each reporting its own status — a failed WhatsApp
 * next to a sent email and an SMS fallback, never folded into one state.
 */
export function ChannelGroups({ groups }: { groups: NotificationGroup[] }) {
  const { t } = useTranslation()
  const { dateTime, digits } = useFormat()

  return (
    <ul className="m-0 flex list-none flex-col p-0">
      {groups.map((group) => {
        const present = CHANNELS.filter((channel) => group.channels[channel])
        return (
          <li key={group.group_key} className="flex flex-col gap-2 border-b border-app-line py-2.75 last:border-b-0" data-testid="notification-group">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <strong className="text-13 font-semibold">{t(`notifications.events.${group.event}`, { defaultValue: group.event })}</strong>
              <span className="font-display text-12 text-app-muted">
                {[group.recipient.type === 'staff' ? t('notifications.toStaff', { name: group.recipient.name ?? '' }) : t('notifications.toCustomer'), group.created_at ? dateTime(group.created_at) : null]
                  .filter(Boolean)
                  .join(' · ')}
              </span>
            </div>
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-3">
              {CHANNELS.map((channel) => {
                const message = group.channels[channel]
                const note = message ? statusNote(message, t) : null
                return (
                  <div
                    key={channel}
                    className={`flex min-w-0 flex-col gap-1 rounded-10 px-2.5 py-2 ${message ? 'bg-app-surface-2' : 'border border-dashed border-app-line'}`}
                    data-testid={`channel-${channel}`}
                  >
                    <span className="flex flex-wrap items-center justify-between gap-1.5">
                      <ChannelChip channel={channel} />
                      {message ? <DeliveryStatus message={message} /> : <span className="text-12 text-app-muted">{t('notifications.notUsed')}</span>}
                    </span>
                    {message ? (
                      <>
                        {message.fallback_of_id ? <span className="text-11 font-semibold text-amber">{t('notifications.fallbackNote')}</span> : null}
                        {message.to ? <span className="truncate font-display text-11 text-app-muted">{digits(message.to)}</span> : null}
                        {note ? <span className="text-11 leading-1.4 text-app-muted">{note}</span> : null}
                        <CostNote message={message} />
                      </>
                    ) : null}
                  </div>
                )
              })}
            </div>
            {present.map((channel) => (
              <MessageBody key={channel} message={group.channels[channel]!} label={t('notifications.showChannelMessage', { channel: CHIP[channel].label.replace(/^\S+\s/, '') })} />
            ))}
          </li>
        )
      })}
    </ul>
  )
}
