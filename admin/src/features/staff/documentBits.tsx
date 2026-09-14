import { useTranslation } from 'react-i18next'

import { Badge } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import type { DocumentStatus, StaffDocument } from './api'

const TONES: Record<DocumentStatus, 'red' | 'orange' | 'blue' | 'green' | 'slate'> = {
  expired: 'red',
  expiring: 'red',
  renew_soon: 'orange',
  valid: 'green',
  no_expiry: 'slate',
}

/** Valid · Renew soon · Expiring · Expired, as the design's Vault shows them. */
export function DocumentStatusBadge({ status }: { status: DocumentStatus }) {
  const { t } = useTranslation()
  return <Badge tone={TONES[status]}>{t(`vault.states.${status}`)}</Badge>
}

/** The expiry date and how far away it is, in Dhaka days. */
export function ExpiryNote({ document }: { document: Pick<StaffDocument, 'expires_on' | 'days_left' | 'status'> }) {
  const { t } = useTranslation()
  const { date, number } = useFormat()

  if (document.expires_on === null || document.days_left === null) return <span className="text-12 text-app-muted">—</span>
  const days = document.days_left

  return (
    <span className="flex flex-col gap-0.5">
      <span className="font-display text-13">{date(document.expires_on)}</span>
      <span className={`text-12 ${document.status === 'expired' || document.status === 'expiring' ? 'font-semibold text-red' : 'text-app-muted'}`}>
        {days === 0 ? t('vault.expiresToday') : days > 0 ? t('vault.daysLeft', { count: days, n: number(days) }) : t('vault.expiredAgo', { count: -days, n: number(-days) })}
      </span>
    </span>
  )
}
