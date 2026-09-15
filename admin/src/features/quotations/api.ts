import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import type { HotelCategory, RoomType } from '@bhabaghure/pricing'

import { useToast } from '../../components/ui/feedback'
import { api, fetchDocument } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import type { BookingFormOptions } from '../bookings/api'

export type QuotationStatus = 'draft' | 'sent' | 'accepted' | 'declined' | 'withdrawn' | 'converted'
/** What staff see: a sent quotation past its date shows as expired (derived from valid_until in Dhaka time). */
export type QuotationDisplayStatus = QuotationStatus | 'expired'
export type QuotationFilter = QuotationDisplayStatus | 'expiring'

export const QUOTATION_FILTERS = ['draft', 'sent', 'expiring', 'expired', 'accepted', 'converted', 'declined', 'withdrawn'] as const

export type QuotationAction = 'edit' | 'send' | 'accept' | 'decline' | 'withdraw' | 'revise' | 'convert' | 'delete' | 'assign'

/** api/app/Http/Resources/AdminQuotation.php — money is a number. */
export type QuotationRow = {
  id: number
  number: string
  status: QuotationStatus
  display_status: QuotationDisplayStatus
  /** Sent and running out within 48 hours — what the sidebar badge counts. */
  expiring: boolean
  customer: { id: number; name: string; phone: string; email: string | null }
  package_title_en: string
  package_title_bn: string | null
  travel_date: string | null
  duration_days: number | null
  pax_count: number
  room_type: RoomType
  total_amount: number
  validity_days: number
  valid_until: string
  sent_at: string | null
  created_at: string
  assigned_staff: { id: number; name: string } | null
  revision_of: { id: number; number: string } | null
  converted_booking: { id: number; reference: string } | null
  actions: Record<QuotationAction, boolean>
}

export type QuotationInputs = {
  package_slug: string | null
  travel_date: string | null
  pax: number
  room: RoomType
  /** For a package priced by hotel category (Phase 8 §4.D). */
  hotel_category: HotelCategory | null
  addons: string[]
  discount: number
  vat_rate: number
  validity_days: number
  locale: 'bn' | 'en'
  notes: string | null
}

export type QuotationDetail = QuotationRow & {
  package_code: string | null
  duration_nights: number | null
  includes_airfare: boolean | null
  locale: 'bn' | 'en'
  notes: string | null
  amounts: { list_price: number; unit_price: number; subtotal: number; single_supplement: number; addons: number; discount: number; vat_rate: number; vat: number; total: number }
  lines: { kind: 'package' | 'single_supplement' | 'addon'; code: string | null; title_en: string; title_bn: string | null; quantity: number; unit_price: number; amount: number }[]
  inputs: QuotationInputs
  created_by: { id: number; name: string } | null
  history: Partial<Record<'created_at' | 'sent_at' | 'accepted_at' | 'declined_at' | 'withdrawn_at' | 'converted_at', string>>
  revisions: { id: number; number: string; status: QuotationDisplayStatus }[]
  public_url: string | null
}

export type QuotationSummary = {
  open: { count: number; total: number }
  expiring: number
  conversion: { sent: number; converted: number; percent: number | null }
  average_value: number | null
}

export type QuotationOptions = BookingFormOptions & { vat_rates: number[]; validity_days: number[] }

export type QuotationFilters = { status: QuotationFilter | 'all'; owner: 'all' | 'mine'; search: string; page: number }

type StatusCounts = Record<(typeof QUOTATION_FILTERS)[number], number>

export function useQuotations(filters: QuotationFilters) {
  const params = new URLSearchParams({ page: String(filters.page) })
  if (filters.status !== 'all') params.set('status', filters.status)
  if (filters.owner !== 'all') params.set('owner', filters.owner)
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: ['quotations', filters.status, filters.owner, filters.search.trim(), filters.page],
    queryFn: ({ signal }) => api.get<Paginated<QuotationRow> & { meta: { status_counts: StatusCounts } }>(`admin/quotations?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useQuotationSummary() {
  return useQuery({ queryKey: ['quotations', 'summary'], queryFn: ({ signal }) => api.get<Data<QuotationSummary>>('admin/quotations/summary', signal).then((r) => r.data) })
}

export function useQuotationOptions(enabled = true) {
  return useQuery({ queryKey: ['quotation-options'], queryFn: ({ signal }) => api.get<Data<QuotationOptions>>('admin/quotations/options', signal).then((r) => r.data), enabled })
}

export function useQuotation(id: number) {
  return useQuery({ queryKey: ['quotation', id], queryFn: ({ signal }) => api.get<Data<QuotationDetail>>(`admin/quotations/${id}`, signal) })
}

/** The body the API prices: the editor's inputs and the total it showed. */
export type QuotationBody = Omit<QuotationInputs, 'package_slug'> & { package_slug: string; expected_total: number }

export const quotationActions = {
  create: (body: QuotationBody & { customer_id: number }) => api.post<Data<QuotationDetail>>('admin/quotations', body),
  update: (id: number, body: QuotationBody) => api.put<Data<QuotationDetail>>(`admin/quotations/${id}`, body),
  transition: (id: number, action: 'send' | 'accept' | 'decline' | 'withdraw' | 'revise', body?: { reason?: string | null }) => api.post<Data<QuotationDetail>>(`admin/quotations/${id}/${action}`, body),
  assign: (id: number, body: { staff_id: number; reason: string }) => api.post<Data<QuotationDetail>>(`admin/quotations/${id}/assign`, body),
  convert: (id: number, body: { travel_date: string | null; travellers: { name: string; passport_number: string | null; phone: string | null }[] }) =>
    api.post<Data<{ booking: { id: number; reference: string }; quotation: QuotationDetail }>>(`admin/quotations/${id}/convert`, body),
  remove: (id: number) => api.delete<null>(`admin/quotations/${id}`),
}

/** Whole days from today (Dhaka) to a YYYY-MM-DD date: 0 today, negative once past. */
export const daysLeft = (validUntil: string) => Math.round((Date.parse(validUntil) - Date.parse(todayInDhaka())) / 86_400_000)

/** Opens the quotation PDF (fetched with the staff token) in a new tab; header off prints for the pre-printed pad. */
export function useOpenQuotationPdf() {
  const { t } = useTranslation()
  const { locale } = useFormat()
  const toast = useToast()
  return async (id: number, header = true) => {
    try {
      const blob = await fetchDocument(`admin/quotations/${id}/pdf?lang=${locale}&header=${header ? 1 : 0}`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('bookings.pdfFailed'), 'error')
    }
  }
}

/**
 * A quotation action: the detail is replaced with the answer; lists, KPIs and the customer's profile refresh (badges
 * refresh after every mutation, App.tsx).
 */
export function useQuotationMutation<TVariables, TResult>(send: (variables: TVariables) => Promise<TResult>, detailOf: (result: TResult) => QuotationDetail | null) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      const detail = detailOf(response)
      // Other quotations can change too: sending a revision withdraws the one it replaces.
      void client.invalidateQueries({ queryKey: ['quotation'], predicate: (query) => query.queryKey[1] !== detail?.id })
      if (detail) client.setQueryData(['quotation', detail.id], { data: detail })
      void client.invalidateQueries({ queryKey: ['quotations'] })
      void client.invalidateQueries({ queryKey: ['customer'] })
      void client.invalidateQueries({ queryKey: ['customers'] })
    },
  })
}
