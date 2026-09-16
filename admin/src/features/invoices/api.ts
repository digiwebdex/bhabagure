import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** docs/phase-9-accounts.md §5: invoices staff write themselves, beside the ones a booking issues. */
export const INVOICE_STATES = ['all', 'draft', 'unpaid', 'partial', 'paid', 'overdue', 'void'] as const
export type InvoiceState = (typeof INVOICE_STATES)[number]

export type InvoiceLine = {
  id?: number
  title: string
  detail: string | null
  note: string | null
  quantity: number
  unit_price: number
  discount_amount: number
  vat_rate: number
  vat_amount: number
  line_total: number
}

export type InvoiceRow = {
  id: number
  number: string | null
  kind: 'booking' | 'deal'
  title: string | null
  customer: { id: number; name: string; phone: string; email: string | null } | null
  billed_name: string | null
  issued_on: string | null
  due_on: string | null
  days_overdue: number
  total: number
  paid: number
  due: number
  status: 'draft' | 'issued' | 'void'
  payment_status: 'unpaid' | 'partial' | 'paid'
  overdue: boolean
  booking_id: number | null
  actions: { edit: boolean; issue: boolean; pay: boolean; void: boolean; share: boolean }
}

export type InvoiceDetail = InvoiceRow & {
  note: string | null
  footer: string | null
  discount_label: string | null
  discount_amount: number
  subtotal: number
  vat_rate: number
  vat_amount: number
  customer_id: number | null
  client_id: number | null
  lines: InvoiceLine[]
  share_url: string | null
  pdf_url: string | null
}

export type InvoiceFilters = { state: InvoiceState; customer_id: string; from: string; to: string; search: string; page: number }

type List = {
  data: InvoiceRow[]
  meta: {
    current_page: number
    last_page: number
    total: number
    tabs: Record<Exclude<InvoiceState, 'void'>, number>
    totals: { invoiced: number; due: number }
  }
}

export function useInvoices(filters: InvoiceFilters) {
  const params = new URLSearchParams({ state: filters.state, page: String(filters.page) })
  for (const key of ['customer_id', 'from', 'to', 'search'] as const) if (filters[key]) params.set(key, filters[key])
  return useQuery({
    queryKey: ['invoices', params.toString()],
    queryFn: ({ signal }) => api.get<List>(`admin/invoices?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useInvoice(id: number | null) {
  return useQuery({
    queryKey: ['invoice', id],
    queryFn: ({ signal }) => api.get<Data<InvoiceDetail>>(`admin/invoices/${id}`, signal),
    enabled: id !== null,
  })
}

export function useInvoiceAction<TVariables, TResult>(send: (variables: TVariables) => Promise<TResult>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['invoices'] })
      void client.invalidateQueries({ queryKey: ['invoice'] })
      void client.invalidateQueries({ queryKey: ['payments'] })
    },
  })
}

export type InvoiceInput = {
  customer_id: number | null
  /** Nobody picked from the list: these make the customer record the invoice is billed to. */
  customer: { name: string; phone: string } | null
  title: string
  note: string | null
  footer: string | null
  due_on: string | null
  discount_label: string | null
  discount_amount: number
  vat_rate: number
  lines: { title: string; detail: string | null; quantity: number; unit_price: number; discount_amount: number; vat_rate: number }[]
}

export const invoiceActions = {
  create: (input: InvoiceInput) => api.post<Data<InvoiceDetail>>('admin/invoices', input),
  update: (id: number) => (input: InvoiceInput) => api.put<Data<InvoiceDetail>>(`admin/invoices/${id}`, input),
  issue: (id: number) => () => api.post<Data<InvoiceDetail>>(`admin/invoices/${id}/issue`),
  void: (id: number) => ({ reason }: { reason: string }) => api.post<Data<InvoiceDetail>>(`admin/deals/${id}/void`, { reason }),
}

/**
 * What a line and the whole invoice come to, worked out as the API does it: quantity × price, less the line's own
 * discount, then its own VAT; the invoice's discount comes off the sum of the lines.
 */
export function lineTotals(line: { quantity: number; unit_price: number; discount_amount: number; vat_rate: number }) {
  const gross = Math.round(line.quantity * line.unit_price * 100) / 100
  const discount = Math.min(line.discount_amount || 0, gross)
  const net = gross - discount
  return { net, vat: Math.round((net * (line.vat_rate || 0)) / 100 * 100) / 100 }
}

export function invoiceTotals(lines: InvoiceInput['lines'], documentDiscount: number) {
  const totals = lines.reduce((sum, line) => {
    const { net, vat } = lineTotals(line)
    return { subtotal: sum.subtotal + net, vat: sum.vat + vat }
  }, { subtotal: 0, vat: 0 })
  const discount = Math.min(documentDiscount || 0, totals.subtotal)
  return { subtotal: totals.subtotal, discount, vat: totals.vat, total: totals.subtotal - discount + totals.vat }
}
