import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { ErrorNotice } from '../../components/ui/feedback'
import { Card, CardTitle, Loading } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { InvoiceDetails } from '../invoices/InvoiceDetails'
import { InvoiceStateBadge } from '../invoices/InvoiceStateBadge'
import { useCustomerAccount } from './api'

/**
 * A customer's invoices on their own page (docs/phase-9-accounts.md §9): what they were invoiced, what they paid and
 * what they still owe, then every invoice with the payments against it. An invoice opens the same view the Invoices
 * screen does.
 */
export function CustomerInvoicesCard({ customerId }: { customerId: number }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const account = useCustomerAccount(customerId)
  const [open, setOpen] = useState<number | null>(null)

  return (
    <Card>
      <CardTitle
        title={t('customerAccounts.card.title')}
        aside={
          account.data && account.data.data.invoices.length > 0 ? (
            <Link to={`/invoices?customer_id=${customerId}`} className="text-13">
              {t('customerAccounts.card.open')}
            </Link>
          ) : undefined
        }
      />

      {account.isPending ? (
        <Loading />
      ) : account.isError ? (
        <ErrorNotice error={account.error} />
      ) : account.data.data.invoices.length === 0 ? (
        <p className="m-0 text-13 text-app-muted">{t('customerAccounts.card.none')}</p>
      ) : (
        <>
          {/* Side by side while they fit; a lakh-sized figure on a phone wraps rather than spilling out. */}
          <dl className="m-0 flex flex-wrap gap-2" data-testid="customer-balance">
            {(['total', 'paid', 'due'] as const).map((key) => (
              <div key={key} className={`flex min-w-28 flex-1 flex-col gap-0.5 rounded-10 px-3 py-2 ${key === 'due' && account.data.data.totals.due > 0 ? 'bg-orange-tint' : 'bg-app-surface-2'}`}>
                <dt className="text-12 text-app-muted">{t(`customerAccounts.card.${key === 'total' ? 'invoiced' : key}`)}</dt>
                <dd className="m-0 font-display text-15 font-bold">{bdt(account.data.data.totals[key])}</dd>
              </div>
            ))}
          </dl>

          <ol className="m-0 flex list-none flex-col gap-2 p-0" data-testid="customer-invoices">
            {account.data.data.invoices.map((invoice) => (
              <li key={invoice.id} className={`flex flex-col gap-1.5 rounded-10 border border-app-line p-3 ${invoice.status === 'void' ? 'opacity-60' : ''}`}>
                <span className="flex flex-wrap items-center justify-between gap-2">
                  <span className="flex items-center gap-2">
                    <button type="button" className="cursor-pointer border-0 bg-transparent p-0 font-display text-13 font-bold text-blue" onClick={() => setOpen(invoice.id)}>
                      #{invoice.number}
                    </button>
                    <span className="text-12 text-app-muted">{invoice.issued_on ? date(invoice.issued_on) : ''}</span>
                  </span>
                  <InvoiceStateBadge invoice={invoice} />
                </span>
                {invoice.title ? <span className="text-13">{invoice.title}</span> : null}
                <span className="flex flex-wrap justify-between gap-x-4 gap-y-1 text-12 text-app-muted">
                  <span>
                    {t('invoices.columns.total')} <strong className="font-display text-app-text">{bdt(invoice.total)}</strong>
                  </span>
                  <span>
                    {t('customerAccounts.card.paid')} <strong className="font-display text-app-text">{bdt(invoice.paid)}</strong>
                  </span>
                  <span>
                    {t('customerAccounts.card.due')} <strong className={`font-display ${invoice.due > 0 && invoice.status === 'issued' ? 'text-app-text' : ''}`}>{bdt(invoice.due)}</strong>
                  </span>
                </span>
                {invoice.payments.length > 0 ? (
                  <ul className="m-0 flex list-none flex-col gap-0.5 border-t border-app-line p-0 pt-1.5 text-12 text-app-muted">
                    {invoice.payments.map((payment) => (
                      <li key={payment.id} className={`flex justify-between gap-3 ${payment.reversed ? 'line-through' : ''}`}>
                        <span>
                          {t('customerAccounts.card.paidOn', { date: payment.date ? date(payment.date) : '—', method: t(`bookings.methods.${payment.method}`, { defaultValue: payment.method }) })}
                          {payment.reversed ? ` · ${t('customerAccounts.card.reversed')}` : ''}
                        </span>
                        <span className="font-display">{bdt(payment.amount)}</span>
                      </li>
                    ))}
                  </ul>
                ) : invoice.status === 'issued' ? (
                  <span className="text-12 text-app-muted">{t('customerAccounts.card.noPayments')}</span>
                ) : null}
              </li>
            ))}
          </ol>
        </>
      )}

      {open !== null ? <InvoiceDetails id={open} onClose={() => setOpen(null)} /> : null}
    </Card>
  )
}
