import { useTranslation } from 'react-i18next'

import { Badge } from '../../components/ui/layout'
import type { InvoiceRow } from './api'

/** An invoice's state as every money screen shows it: draft, void, overdue, or how much of it is paid. */
export function InvoiceStateBadge({ invoice }: { invoice: Pick<InvoiceRow, 'status' | 'payment_status' | 'overdue'> }) {
  const { t } = useTranslation()

  return (
    <Badge tone={invoice.status === 'void' ? 'slate' : invoice.status === 'draft' ? 'blue' : invoice.payment_status === 'paid' ? 'green' : invoice.overdue ? 'red' : 'orange'}>
      {invoice.overdue ? t('invoices.states.overdue') : invoice.status === 'issued' ? t(`invoices.states.${invoice.payment_status}`) : t(`invoices.states.${invoice.status}`)}
    </Badge>
  )
}
