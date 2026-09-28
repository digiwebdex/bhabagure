import { useQuery } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { api } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useFormat } from '../../lib/useFormat'
import { Dropdown } from './ItemPicker'
import { useDismiss } from './useDismiss'

export type CustomerHit = { id: number; name: string; phone: string; email?: string | null }

/**
 * "Find a customer" on the invoice form: clicking the box lists the customers straight away, newest first, and typing
 * a name or number narrows it — the Customers list's own search, so staff see only customers they may see.
 */
export function CustomerFinder({ onPick }: { onPick: (customer: CustomerHit) => void }) {
  const { t } = useTranslation()
  const { digits } = useFormat()
  const id = useId()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const box = useRef<HTMLDivElement>(null)
  useDismiss(box, open, () => setOpen(false))

  const search = query.trim()
  const customers = useQuery({
    queryKey: ['invoice-customers', search],
    queryFn: ({ signal }) => api.get<Paginated<CustomerHit>>(`admin/customers?${new URLSearchParams(search ? { search } : {})}`, signal),
    enabled: open,
    placeholderData: (previous) => previous,
    staleTime: 30_000,
  })

  return (
    <div ref={box} className="relative flex min-w-0 flex-col gap-1.25">
      <label htmlFor={id} className="text-13 text-app-muted">
        {t('invoices.findCustomer')}
      </label>
      <input
        id={id}
        type="search"
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${id}-list`}
        value={query}
        placeholder={t('invoices.findCustomerHint')}
        onFocus={() => setOpen(true)}
        onClick={() => setOpen(true)}
        onChange={(event) => {
          setQuery(event.target.value)
          setOpen(true)
        }}
        className={controlClass()}
      />
      {open ? (
        <Dropdown testId="customer-options">
          <div id={`${id}-list`} role="listbox" aria-label={t('invoices.customers')} className="max-h-72 overflow-y-auto">
            {customers.isPending ? <p className="m-0 p-2 text-13 text-app-muted">{t('common.loading')}</p> : null}
            {customers.isError ? <ErrorNotice error={customers.error} /> : null}
            {customers.data && customers.data.data.length === 0 ? <p className="m-0 p-2 text-13 text-app-muted">{t('invoices.noCustomerFound')}</p> : null}
            {(customers.data?.data ?? []).map((customer) => (
              <button
                key={customer.id}
                type="button"
                role="option"
                aria-selected={false}
                onClick={() => {
                  onPick(customer)
                  setQuery('')
                  setOpen(false)
                }}
                className="flex w-full cursor-pointer items-center justify-between gap-3 rounded-8 px-2 py-2 text-left hover:bg-app-surface-2"
              >
                <span className="truncate text-14">{customer.name}</span>
                <span className="shrink-0 font-display text-12 text-app-muted">{digits(customer.phone.replace(/^88/, ''))}</span>
              </button>
            ))}
          </div>
        </Dropdown>
      ) : null}
    </div>
  )
}
