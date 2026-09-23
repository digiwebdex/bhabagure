import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** docs/coupons.md — api/app/Http/Resources/AdminCoupon.php. */
export const COUPON_STATUSES = ['active', 'scheduled', 'expired', 'used_up', 'inactive', 'archived'] as const
export type CouponStatus = (typeof COUPON_STATUSES)[number]
export type CouponKind = 'public' | 'passport'
export type DiscountType = 'percent' | 'fixed'

export type CouponUsage = { live: number; used: number; pending: number; released: number; discount_given: number; revenue: number }

export type Coupon = {
  id: number
  code: string
  name: string
  kind: CouponKind
  channel: string | null
  discount_type: DiscountType
  discount_value: number
  max_discount_amount: number | null
  min_booking_amount: number | null
  /** UTC; shown and edited in Dhaka time. */
  starts_at: string | null
  ends_at: string | null
  usage_limit: number | null
  per_customer_limit: number | null
  applies_to: 'all' | 'packages'
  packages: { id: number; title: string }[]
  /** In full only for staff who manage coupons (the edit form). */
  passport_number: string | null
  passport_masked: string | null
  holder_name: string | null
  is_active: boolean
  notes: string | null
  status: CouponStatus
  usage: CouponUsage
  /** Deleting a coupon nothing ever used removes it; otherwise it is archived. */
  ever_used: boolean
  archived_at: string | null
  created_by: string | null
  created_at: string
  updated_at: string
}

/** What the form sends: times as the date-and-time fields give them, in Dhaka. */
export type CouponForm = {
  code: string
  name: string
  kind: CouponKind
  channel: string | null
  discount_type: DiscountType
  discount_value: number | null
  max_discount_amount: number | null
  min_booking_amount: number | null
  starts_at: string | null
  ends_at: string | null
  usage_limit: number | null
  per_customer_limit: number | null
  applies_to: 'all' | 'packages'
  package_ids: number[]
  passport_number: string | null
  holder_name: string | null
  is_active: boolean
  notes: string | null
}

export type CouponFilters = { status: CouponStatus | 'all'; search: string; page: number }

type CouponList = { data: Coupon[]; meta: { current_page: number; last_page: number; total: number; status_counts: Record<CouponStatus | 'all', number> } }

export type CouponOptions = { packages: { id: number; title: string; published: boolean }[]; channels: string[] }

export function useCoupons({ status, search, page }: CouponFilters) {
  const params = new URLSearchParams({ page: String(page) })
  if (status !== 'all') params.set('status', status)
  if (search.trim()) params.set('search', search.trim())
  return useQuery({
    queryKey: ['coupons', 'list', params.toString()],
    queryFn: ({ signal }) => api.get<CouponList>(`admin/coupons?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useCouponOptions(enabled = true) {
  return useQuery({ queryKey: ['coupons', 'options'], queryFn: ({ signal }) => api.get<Data<CouponOptions>>('admin/coupons/options', signal), enabled })
}

/** Every change answers with the coupon; the lists and the report are fetched again. */
function useCouponMutation<TVariables, TResult>(send: (variables: TVariables) => Promise<TResult>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: () => void client.invalidateQueries({ queryKey: ['coupons'] }) })
}

export const useSaveCoupon = () =>
  useCouponMutation(({ id, form }: { id: number | null; form: CouponForm }) =>
    id === null ? api.post<Data<Coupon>>('admin/coupons', form) : api.put<Data<Coupon>>(`admin/coupons/${id}`, form),
  )

export const useCouponAction = () =>
  useCouponMutation(({ id, action }: { id: number; action: 'activate' | 'deactivate' | 'restore' }) => api.post<Data<Coupon>>(`admin/coupons/${id}/${action}`))

export const useDeleteCoupon = () => useCouponMutation((id: number) => api.delete<Data<{ result: 'deleted' | 'archived' }>>(`admin/coupons/${id}`))

// ── The report (api/app/Services/Coupons/CouponReport.php) ──────────────────────────────────────────────────────────

export type UseStatus = 'reserved' | 'used' | 'released'

export type CouponUse = {
  id: number
  coupon_id: number
  code: string
  kind: CouponKind
  coupon_name: string | null
  holder_name: string | null
  booking: { id: number; reference: string; status: 'inquiry' | 'confirmed' | 'completed' | 'cancelled'; package_title: string } | null
  customer: { id: number; name: string; phone: string } | null
  passport_masked: string | null
  discount_type: DiscountType
  discount_value: number
  eligible_amount: number
  discount_amount: number
  original_total: number
  final_total: number
  status: UseStatus
  source: 'website' | 'office'
  applied_at: string
  used_at: string | null
  released_at: string | null
  release_reason: string | null
}

type Figures = { used: number; pending: number; discount_given: number; revenue: number }

export type CouponReport = {
  totals: Figures & { coupons: number; active: number; expired: number; released: number }
  coupons: (Figures & { id: number; code: string; name: string; kind: CouponKind; channel: string | null; status: CouponStatus; released: number })[]
  campaigns: (Figures & { name: string; channels: string[]; coupons: number })[]
  passport_uses: CouponUse[]
  uses: CouponUse[]
}

export type ReportFilters = { from: string; to: string; coupon: number | null; status: UseStatus | 'all'; search: string; passport: string; page: number }

export function useCouponReport(filters: ReportFilters) {
  const params = new URLSearchParams({ page: String(filters.page) })
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  if (filters.coupon) params.set('coupon_id', String(filters.coupon))
  if (filters.status !== 'all') params.set('status', filters.status)
  if (filters.search.trim()) params.set('search', filters.search.trim())
  if (filters.passport.trim()) params.set('passport', filters.passport.trim())
  return useQuery({
    queryKey: ['coupons', 'report', params.toString()],
    queryFn: ({ signal }) => api.get<{ data: CouponReport; meta: { current_page: number; last_page: number; total: number } }>(`admin/coupon-report?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}
