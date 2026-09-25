import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { Addon, CouponTerms, HotelCategory, PriceGrid, PricingConfig, RoomType } from '@bhabaghure/pricing'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'
import type { DocumentSlot } from '../documents/api'
import type { NotificationGroup } from '../notifications/api'
import type { Voucher } from '../vouchers/api'

/** api/app/Services/Booking/BookingFormOptions.php — what the staff booking form and the quotation editor price with. */
export type BookingFormOptions = {
  packages: { slug: string; title_en: string; title_bn: string | null; duration_days: number | null; list_price: number; price_grid: PriceGrid | null; departures: { date: string; seats_left: number | null }[] }[]
  addons: { code: string; name_en: string; name_bn: string | null; price: number; unit: 'per_person' | 'per_booking' }[]
  config: { slabs: { minPax: number; discountPercent: number }[]; singleRoomSupplementPercent: number; serviceChargePercent: number; maxTravellers: number; onlinePaymentChargePercent: number }
  sources: string[]
}

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
  /** A custom service rather than a package (docs/custom-service-bookings.md); the title is the service's name. */
  is_custom: boolean
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
  /** The lead's mobile proved itself with a code before the website saved the booking (docs/booking-phone-verification.md). */
  phone_verified_at: string | null
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
  /** The whole discount: the coupon's and the staff's own. */
  discount_amount: number
  /** The coupon's share of discount_amount (docs/coupons.md §2.5). */
  coupon_discount_amount: number
  /** The coupon in this booking's price, as its use copied it; null without one. */
  coupon: {
    id: number
    coupon_id: number
    code: string
    kind: 'public' | 'passport'
    discount_type: 'percent' | 'fixed'
    discount_value: number
    max_discount_amount: number | null
    min_booking_amount: number | null
    discount_amount: number
    status: 'reserved' | 'used' | 'released'
    source: 'website' | 'office'
    applied_at: string
  } | null
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
  /** 'custom': an item of a custom service (docs/custom-service-bookings.md), named by its title. */
  lines: { kind: 'package' | 'single_supplement' | 'addon' | 'custom'; code: string | null; title_en: string; title_bn: string | null; quantity: number; unit_price: number; amount: number }[]
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
    /** Portal documents (docs/phase-6-customer-portal.md §3.3): passport scan, photo, visa, insurance. */
    documents: DocumentSlot[]
  }[]
  /** The trip's destination gives the visa on arrival: visa and insurance default to not required. */
  visa_on_arrival: boolean
  /** E-tickets per traveller, voided ones included. */
  /** Suppliers' confirmation vouchers for this booking (docs/booking-vouchers.md); null without vouchers.view. */
  vouchers: Voucher[] | null
  tickets: { id: number; travellerId: number; airline: string; pnr: string; ticketNumber: string; route: string | null; departsOn: string | null; hasFile: boolean; issuedAt: string; issuedBy: string | null; voidedAt: string | null; voidedBy: string | null; voidReason: string | null }[]
  /** The customer's rating after the trip, from the portal. */
  nps: { score: number; comment: string | null; created_at: string } | null
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
    has_evidence: boolean
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
  actions: Record<'edit_quote' | 'apply_coupon' | 'remove_coupon' | 'issue_invoice' | 'void_invoice' | 'record_payment' | 'reverse_payment' | 'confirm' | 'complete' | 'cancel' | 'send_whatsapp' | 'toggle_whatsapp_opt_out' | 'claim' | 'assign' | 'review_documents' | 'manage_tickets' | 'upload_voucher', boolean>
  quote_inputs: {
    list_price: number
    grid: PriceGrid | null
    hotel_category: HotelCategory | null
    addons: Addon[]
    /** A custom service's items as booked, each at a price per person; null for a package. */
    custom_items: { title: string; unitPrice: number }[] | null
    /** The booking's live coupon, for couponDiscount on new lines; null without one. */
    coupon: CouponTerms | null
    config: PricingConfig
  }
  /** Phase 8 §4.D: the hotel category a grid package was booked in. */
  hotel_category: HotelCategory | null
  payment_methods: string[]
  /** What bKash adds on top of a payment (Phase 8 §4.F), from Site settings → Payment; null when none is set. */
  bkash_charge_percent: number | null
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
  /** `discount` is the staff's own, on top of any coupon. */
  quote: (id: number) => (body: { pax: number; room: RoomType; discount: number; vat_rate: number; expected_total: number }) =>
    api.put<Data<BookingDetail>>(`admin/bookings/${id}/quote`, body),
  /** A customer's coupon on the draft invoice (docs/coupons.md §2.5): the API checks it and works the discount out. */
  applyCoupon: (id: number) => (code: string) => api.post<Data<BookingDetail>>(`admin/bookings/${id}/coupon`, { code }),
  removeCoupon: (id: number) => () => api.delete<Data<BookingDetail>>(`admin/bookings/${id}/coupon`),
  issue: (id: number) => () => api.post<Data<BookingDetail>>(`admin/bookings/${id}/invoice`),
  void: () => ({ invoiceId, reason }: { invoiceId: number; reason: string }) => api.post<Data<BookingDetail>>(`admin/invoices/${invoiceId}/void`, { reason }),
  /** Multipart: the receipt goes with the payment. */
  // bkash_charge travels as '1' or '' (FormData carries strings; '' is left out, as every blank field is).
  pay: (id: number) => ({ evidence, ...fields }: { amount: number; method: string; reference: string; occurred_at: string; note: string; evidence: File; bkash_charge: '1' | '' }) => {
    const body = new FormData()
    for (const [key, value] of Object.entries(fields)) if (value !== '') body.append(key, String(value))
    body.append('evidence', evidence)
    return api.post<Data<BookingDetail>>(`admin/bookings/${id}/payments`, body)
  },
  reverse: () => ({ transactionId, reason }: { transactionId: number; reason: string }) => api.post<Data<BookingDetail>>(`admin/transactions/${transactionId}/reverse`, { reason }),
  transition: (id: number) => ({ action, reason }: { action: 'confirm' | 'complete' | 'cancel'; reason?: string }) =>
    api.post<Data<BookingDetail>>(`admin/bookings/${id}/${action}`, reason ? { reason } : undefined),
  traveller: () => ({ travellerId, ...body }: TravellerDetails & { travellerId: number }) => api.put<Data<BookingDetail>>(`admin/booking-travellers/${travellerId}`, body),
}

/** What staff can complete on a traveller; blank optional fields go as null. */
export type TravellerDetails = {
  full_name: string
  phone: string | null
  email: string | null
  passport_number: string | null
  date_of_birth: string | null
  passport_expiry: string | null
}
