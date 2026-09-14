import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { Addon, PricingConfig, RoomType } from '@bhabaghure/pricing'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'
import type { NotificationGroup } from '../notifications/api'

export type BookingStatus = 'inquiry' | 'confirmed' | 'completed' | 'cancelled'
export type PaymentStatus = 'unpaid' | 'partial' | 'paid'

export type BookingSummary = {
  id: number
  reference: string
  status: BookingStatus
  payment_status: PaymentStatus
  customer: { id: number; name: string; phone: string; email: string | null } | null
  package_title_en: string
  package_title_bn: string | null
  travel_start: string | null
  pax_count: number
  total_amount: number
  paid_amount: number
  due_amount: number
  source: string
  assigned_staff: { id: number; name: string } | null
  /** In the shared pool: unowned and still an inquiry. */
  claimable: boolean
  has_invoice: boolean
  has_payments: boolean
  created_at: string
}

export type BookingDetail = BookingSummary & {
  room_type: RoomType
  travel_end: string | null
  list_price: number
  unit_price: number
  subtotal_amount: number
  single_supplement_amount: number
  addons_amount: number
  discount_amount: number
  vat_rate: number
  vat_amount: number
  locale: 'bn' | 'en'
  package_slug: string | null
  assigned_staff: { id: number; name: string } | null
  customer: { id: number; name: string; phone: string; email: string | null; address: string | null; whatsapp_opted_out: boolean } | null
  /** Messages about this booking, newest first: WhatsApp, email and SMS side by side for each. */
  notification_groups: NotificationGroup[]
  /** Customer WhatsApp messages wait until the notifications number is published in site settings. */
  notifications_number_published: boolean
  notifications_sender_line: string
  confirmed_at: string | null
  cancelled_at: string | null
  cancellation_reason: string | null
  terms_accepted_at: string | null
  terms_version: string | null
  lines: { kind: 'package' | 'single_supplement' | 'addon'; code: string | null; title_en: string; title_bn: string | null; quantity: number; unit_price: number; amount: number }[]
  travellers: {
    id: number
    is_lead: boolean
    full_name: string
    date_of_birth: string | null
    nationality: string
    passport_number: string | null
    passport_expiry: string | null
    phone: string | null
    email: string | null
    has_scan: boolean
    ocr_filled: boolean
  }[]
  invoices: {
    id: number
    invoice_number: string | null
    status: 'draft' | 'issued' | 'void'
    issued_on: string | null
    total_amount: number
    paid_amount: number
    balance_due: number
    payment_status: PaymentStatus
    void_reason: string | null
    share_url: string | null
  }[]
  transactions: {
    id: number
    direction: 'in' | 'out'
    amount: number
    category: string
    method: string
    external_ref: string | null
    reference_label: string | null
    description: string
    occurred_at: string
    reverses_transaction_id: number | null
  }[]
  payment_attempts: {
    id: number
    tran_id: string
    status: string
    amount: number
    method_hint: string | null
    card_type: string | null
    bank_tran_id: string | null
    online_charge: number
    gateway_fee: number
    gateway_surcharge: number
    failure_reason: string | null
    risk_level: number | null
    created_at: string
    settled_at: string | null
  }[]
  actions: Record<'edit_quote' | 'issue_invoice' | 'void_invoice' | 'record_payment' | 'reverse_payment' | 'confirm' | 'complete' | 'cancel' | 'send_whatsapp' | 'toggle_whatsapp_opt_out' | 'claim' | 'assign', boolean>
  quote_inputs: { list_price: number; addons: Addon[]; config: PricingConfig }
  payment_methods: string[]
  vat_rates: number[]
}

/** The list's filters live in the URL, so a sidebar badge can open exactly the list it counts. Same names as the API. */
export type BookingFilters = { status: BookingStatus | 'all'; payment_status: PaymentStatus | 'all'; owner: 'mine' | 'pool' | 'all'; search: string; page: number }

export type BookingList = Paginated<BookingSummary> & { meta: { status_counts: Record<BookingStatus, number> } }

export function useBookings({ status, payment_status, owner, search, page }: BookingFilters) {
  const params = new URLSearchParams({ page: String(page) })
  if (status !== 'all') params.set('status', status)
  if (payment_status !== 'all') params.set('payment_status', payment_status)
  if (owner !== 'all') params.set('owner', owner)
  if (search.trim()) params.set('search', search.trim())
  return useQuery({
    queryKey: ['bookings', status, payment_status, owner, search.trim(), page],
    queryFn: ({ signal }) => api.get<BookingList>(`admin/bookings?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useClaimBooking() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api.post<Data<BookingDetail>>(`admin/bookings/${id}/claim`),
    onSuccess: (response) => {
      client.setQueryData(['booking', response.data.id], response)
      void client.invalidateQueries({ queryKey: ['bookings'] })
    },
  })
}

export function useDeleteBooking() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api.delete<null>(`admin/bookings/${id}`),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['bookings'] }),
  })
}

export function useBooking(id: number) {
  return useQuery({ queryKey: ['booking', id], queryFn: ({ signal }) => api.get<Data<BookingDetail>>(`admin/bookings/${id}`, signal) })
}

/** Every booking action answers with the updated booking; the cache takes it directly. */
export function useBookingAction<TVariables>(id: number, send: (variables: TVariables) => Promise<Data<BookingDetail>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      client.setQueryData(['booking', id], response)
      void client.invalidateQueries({ queryKey: ['bookings'] })
    },
  })
}

export const bookingActions = {
  quote: (id: number) => (body: { pax: number; room: RoomType; discount: number; vat_rate: number; expected_total: number }) =>
    api.put<Data<BookingDetail>>(`admin/bookings/${id}/quote`, body),
  issue: (id: number) => () => api.post<Data<BookingDetail>>(`admin/bookings/${id}/invoice`),
  void: () => ({ invoiceId, reason }: { invoiceId: number; reason: string }) => api.post<Data<BookingDetail>>(`admin/invoices/${invoiceId}/void`, { reason }),
  pay: (id: number) => (body: { amount: number; method: string; reference: string; occurred_at: string; note: string }) =>
    api.post<Data<BookingDetail>>(`admin/bookings/${id}/payments`, body),
  reverse: () => ({ transactionId, reason }: { transactionId: number; reason: string }) => api.post<Data<BookingDetail>>(`admin/transactions/${transactionId}/reverse`, { reason }),
  transition: (id: number) => ({ action, reason }: { action: 'confirm' | 'complete' | 'cancel'; reason?: string }) =>
    api.post<Data<BookingDetail>>(`admin/bookings/${id}/${action}`, reason ? { reason } : undefined),
}
