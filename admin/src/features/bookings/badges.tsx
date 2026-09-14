import { useTranslation } from 'react-i18next'

import { Badge } from '../../components/ui/layout'
import type { BookingStatus, PaymentStatus } from './api'

export function BookingStatusBadge({ status }: { status: BookingStatus }) {
  const { t } = useTranslation()
  // The prototype's colours: Confirmed blue, Completed green (docs/phase-5-admin-core.md §9).
  const tone = status === 'confirmed' ? 'blue' : status === 'completed' ? 'green' : status === 'cancelled' ? 'slate' : 'orange'
  return <Badge tone={tone}>{t(`bookings.status.${status}`)}</Badge>
}

/** PAID / PARTIAL / UNPAID — always derived from the ledger by the API, never chosen. */
export function PaymentBadge({ status }: { status: PaymentStatus }) {
  const { t } = useTranslation()
  const tone = status === 'paid' ? 'green' : status === 'partial' ? 'orange' : 'red'
  return <Badge tone={tone}>{t(`bookings.paymentStatus.${status}`)}</Badge>
}
