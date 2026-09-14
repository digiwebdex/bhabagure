import { useTranslation } from 'react-i18next'

import { Badge } from '../../components/ui/layout'
import type { QuotationDisplayStatus } from './api'

/** The prototype's colours: sent blue, expired red, accepted and booked green; drafts and withdrawn ones grey. */
export function QuotationStatusBadge({ status }: { status: QuotationDisplayStatus }) {
  const { t } = useTranslation()
  const tone = status === 'sent' ? 'blue' : status === 'accepted' || status === 'converted' ? 'green' : status === 'expired' || status === 'declined' ? 'red' : 'slate'
  return <Badge tone={tone}>{t(`quotations.status.${status}`)}</Badge>
}
