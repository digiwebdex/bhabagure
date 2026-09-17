import { useQuery } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'
import type { InvoicePaymentRow, InvoiceRow } from '../invoices/api'

/** docs/phase-9-accounts.md §9: customers as the books see them — invoiced, paid, and still owed. */
export const BALANCE_TABS = ['all', 'due'] as const
export type BalanceTab = (typeof BALANCE_TABS)[number]

export const ACCOUNT_SORTS = ['name', 'due', 'recent'] as const
export type AccountSort = (typeof ACCOUNT_SORTS)[number]

export type CustomerAccountRow = {
  id: number
  name: string
  phone: string
  email: string | null
  invoices: number
  total: number
  paid: number
  due: number
  /** 'YYYY-MM': the earlier of being added here and their first invoice. */
  customer_since: string
  last_invoice_on: string | null
}

export type CustomerAccountFilters = { balance: BalanceTab; sort: AccountSort; search: string; page: number }

type List = {
  data: CustomerAccountRow[]
  meta: { current_page: number; last_page: number; total: number; tabs: Record<BalanceTab, number>; totals: { invoiced: number; due: number } }
}

export type CustomerAccount = {
  customer: { id: number; name: string; phone: string; email: string | null }
  totals: { invoices: number; total: number; paid: number; due: number }
  invoices: (InvoiceRow & { payments: InvoicePaymentRow[] })[]
}

export function useCustomerAccounts(filters: CustomerAccountFilters) {
  const params = new URLSearchParams({ balance: filters.balance, sort: filters.sort, page: String(filters.page) })
  if (filters.search) params.set('search', filters.search)
  return useQuery({
    // Under 'invoices', so a payment or a new invoice anywhere refreshes these figures too.
    queryKey: ['invoices', 'customer-accounts', params.toString()],
    queryFn: ({ signal }) => api.get<List>(`admin/customer-accounts?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useCustomerAccount(customerId: number) {
  return useQuery({
    queryKey: ['invoices', 'customer-account', customerId],
    queryFn: ({ signal }) => api.get<Data<CustomerAccount>>(`admin/customer-accounts/${customerId}`, signal),
  })
}
