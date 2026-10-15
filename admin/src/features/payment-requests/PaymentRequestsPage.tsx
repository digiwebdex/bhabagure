import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { ErrorNotice } from '../../components/ui/feedback'
import { TextInput } from '../../components/ui/fields'
import { Card, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { Pager } from '../cms/media/media'
import { usePaymentRequests, type PaymentRequestFilter } from './api'
import { PaymentRequestItem } from './PaymentRequestItem'

/**
 * Sales → Payment requests (docs/payment-requests.md): what customers say they paid by bank or bKash, with their
 * screenshots, oldest waiting first. Each is also on its booking's page; the sidebar badge counts those waiting.
 */
export function PaymentRequestsPage() {
  const { t } = useTranslation()
  const [status, setStatus] = useState<PaymentRequestFilter>('pending')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const list = usePaymentRequests(status, search, page)

  return (
    <>
      <PageHeader title={t('paymentRequests.title')} subtitle={t('paymentRequests.subtitle')} />
      <div className="flex flex-wrap items-end justify-between gap-3">
        <Chips
          label={t('paymentRequests.title')}
          value={status}
          onChange={(value) => {
            setStatus(value)
            setPage(1)
          }}
          options={(['pending', 'approved', 'rejected', 'all'] as const).map((value) => ({ value, label: t(`paymentRequests.filters.${value}`) }))}
        />
        <TextInput
          label={t('paymentRequests.search')}
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          className="w-72 max-w-full"
        />
      </div>
      <Card>
        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <ErrorNotice error={list.error} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title={t(status === 'pending' ? 'paymentRequests.emptyPending' : 'paymentRequests.empty')} />
        ) : (
          <ul className="m-0 flex list-none flex-col p-0" data-testid="payment-request-list">
            {list.data.data.map((row) => (
              <PaymentRequestItem key={row.id} row={row} canDecide showBooking bkashChargePercent={list.data.bkash_charge_percent} />
            ))}
          </ul>
        )}
        {list.data && list.data.meta.last_page > 1 ? <Pager page={page} lastPage={list.data.meta.last_page} onPage={setPage} /> : null}
      </Card>
    </>
  )
}
