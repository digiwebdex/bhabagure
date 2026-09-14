import type { TFunction } from 'i18next'

import type { RowAction } from './DataTable'

/**
 * WhatsApp, SMS and email from the staff member's own device (docs/phase-5-admin-core.md §3.3): wa.me, sms: and mailto:
 * links with the number and address on record — nothing is typed and nothing is sent by the system, so these are not
 * logged. The logged WhatsApp send stays on the booking page. Missing contact details disable the action with a reason.
 *
 * `channels` lets a screen leave one out: the air-ticket queue has no SMS (SMS stays a fallback channel only).
 */
export function contactActions(
  t: TFunction,
  { phone, email, message = '', subject = '', channels = ['whatsapp', 'sms', 'email'] }: { phone?: string | null; email?: string | null; message?: string; subject?: string; channels?: ('whatsapp' | 'sms' | 'email')[] },
): RowAction[] {
  const digits = (phone ?? '').replace(/\D/g, '').replace(/^0/, '880')
  const text = encodeURIComponent(message)
  const noPhone = digits ? undefined : t('table.noPhone')
  const noEmail = email ? undefined : t('table.noEmail')

  const all: Record<'whatsapp' | 'sms' | 'email', RowAction> = {
    whatsapp: { key: 'whatsapp', icon: '✆', label: t('table.whatsapp'), tone: 'green', href: `https://wa.me/${digits}${text ? `?text=${text}` : ''}`, newTab: true, disabledReason: noPhone },
    sms: { key: 'sms', icon: '✉', label: t('table.sms'), tone: 'purple', href: `sms:+${digits}${text ? `?body=${text}` : ''}`, disabledReason: noPhone },
    email: { key: 'email', icon: '@', label: t('table.email'), tone: 'blue', href: `mailto:${email ?? ''}?subject=${encodeURIComponent(subject)}${text ? `&body=${text}` : ''}`, disabledReason: noEmail },
  }

  return channels.map((channel) => all[channel])
}
