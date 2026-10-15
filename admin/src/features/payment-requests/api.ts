import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { NAV_COUNTS_KEY } from '../../app/navCounts'
import { api, fetchDocument } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** Shapes from api/app/Services/Payments/PaymentRequests.php (docs/payment-requests.md). */

export type PaymentRequestStatus = 'pending' | 'approved' | 'rejected'

export type PaymentRequestRow = {
  id: number
  booking: { id: number; reference?: string; status?: string; due?: number; customer?: string | null }
  method: 'bank_transfer' | 'bkash'
  bank_name: string
  account_number: string
  amount: number
  approved_amount: number | null
  paid_on: string
  reference: string | null
  via: 'booking_page' | 'portal'
  status: PaymentRequestStatus
  transaction_id: number | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export type PaymentRequestFilter = PaymentRequestStatus | 'all'

export function usePaymentRequests(status: PaymentRequestFilter, search: string, page: number) {
  return useQuery({
    queryKey: ['payment-requests', status, search.trim(), page],
    queryFn: ({ signal }) =>
      api.get<Paginated<PaymentRequestRow> & { bkash_charge_percent: number | null }>(`admin/payment-requests?status=${status}&page=${page}${search.trim() ? `&search=${encodeURIComponent(search.trim())}` : ''}`, signal),
    placeholderData: (previous) => previous,
  })
}

/** Approving moves money and may confirm the booking: everything showing it reloads. */
export function usePaymentRequestAction<TVariables>(send: (variables: TVariables) => Promise<Data<PaymentRequestRow>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      for (const key of [['payment-requests'], ['booking'], ['bookings'], NAV_COUNTS_KEY, ['cash-book'], ['payments-summary']]) void client.invalidateQueries({ queryKey: key })
    },
  })
}

export const paymentRequestActions = {
  approve: ({ id, amount, bkashCharge }: { id: number; amount: number; bkashCharge: boolean }) => api.post<Data<PaymentRequestRow>>(`admin/payment-requests/${id}/approve`, { amount, bkash_charge: bkashCharge }),
  reject: ({ id, reason }: { id: number; reason: string }) => api.post<Data<PaymentRequestRow>>(`admin/payment-requests/${id}/reject`, { reason }),
}

/** The customer's screenshot, opened in a new tab from a private blob. */
export async function openPaymentScreenshot(id: number): Promise<boolean> {
  try {
    const blob = await fetchDocument(`admin/payment-requests/${id}/file`)
    window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    return true
  } catch {
    return false
  }
}
