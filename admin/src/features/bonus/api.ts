import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** Shapes from api/app/Http/Controllers/Api/V1/Admin/{Bonus,MyCommission}Controller.php (docs/phase-7-hr-attendance-bonus-wallet.md §7). */

export type BonusEntry = {
  id: number
  direction: 'credit' | 'debit'
  amount: number
  kind: 'commission' | 'volume_bonus' | 'manual' | 'withdrawal' | 'reversal'
  reason: string | null
  /** Commission and volume bonus: the rule they were made with. A system reversal: why. */
  rule: EntryRule | null
  booking: { id: number; reference: string } | null
  withdrawal_id: number | null
  reverses_id: number | null
  reversed: boolean
  /** Only on the admin ledger. */
  reversible?: boolean
  by: string | null
  created_at: string | null
}

export type EntryRule =
  | { type: 'tour' | 'air' | 'hotel'; rate: number; base: number; booking: string }
  | { type: 'volume'; month: string; rate: number; threshold: number; bookings: number; base: number }
  | { cause: 'booking_cancelled' | 'returned_to_pool' | 'reassigned'; booking: string; to?: string }

export type WithdrawalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'paid'
export type WithdrawalFilter = 'open' | WithdrawalStatus | 'all'

/** The queue's chips; `open` (pending or approved, not yet paid) is what the Staff badge counts. */
export const WITHDRAWAL_FILTERS: WithdrawalFilter[] = ['open', 'paid', 'rejected', 'cancelled', 'all']

export type Withdrawal = {
  id: number
  staff: { id: number; name: string; employee_code: string }
  amount: number
  note: string | null
  status: WithdrawalStatus
  requested_at: string | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  paid_at: string | null
  paid_by: string | null
  method: string | null
  reference: string | null
  /** Admin lists only. */
  actions?: { approve: boolean; reject: boolean; pay: boolean }
  /** My commission only. */
  cancellable?: boolean
}

export type BonusLedger = {
  staff: { id: number; name: string; employee_code: string }
  balance: number
  held: number
  available: number
  entries: BonusEntry[]
  withdrawals: Withdrawal[]
  actions: { credit: boolean; reverse: boolean }
}

export type WithdrawalList = Paginated<Withdrawal> & { meta: Paginated<Withdrawal>['meta'] & { status_counts: Record<WithdrawalFilter, number>; methods: string[] } }

export type MyCommission = {
  balance: number
  held: number
  available: number
  min_withdrawal: number
  sales: { this_month: { count: number; total: number }; last_month: { count: number; total: number } }
  commission: { this_month: number; total: number }
  rules: { earns: boolean; rates: { tour: number; air: number; hotel: number }; volume_threshold: number; volume_rate: number }
  /** Bookings confirmed this Dhaka month that are earning commission now, and their sale before VAT. */
  volume: { count: number; base: number }
  entries: BonusEntry[]
  withdrawals: Withdrawal[]
}

export function useBonusLedger(staffId: number, enabled = true) {
  return useQuery({ queryKey: ['bonus-ledger', staffId], queryFn: ({ signal }) => api.get<Data<BonusLedger>>(`admin/staff/${staffId}/bonus`, signal), enabled })
}

export function useWithdrawals(status: WithdrawalFilter, page: number, enabled = true) {
  return useQuery({
    queryKey: ['bonus-withdrawals', status, page],
    queryFn: ({ signal }) => api.get<WithdrawalList>(`admin/bonus-withdrawals?withdrawals=${status}&page=${page}`, signal),
    placeholderData: (previous) => previous,
    enabled,
  })
}

export function useMyCommission() {
  return useQuery({ queryKey: ['my-commission'], queryFn: ({ signal }) => api.get<Data<MyCommission>>('admin/profile/commission', signal) })
}

/** Every bonus change moves ledgers, the queue and its badge, the staff list's balances and (paying) the cash book. */
export function useBonusChange<TVariables, TResponse>(send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      for (const key of ['bonus-ledger', 'bonus-withdrawals', 'my-commission', 'staff', 'nav-counts', 'payments']) void client.invalidateQueries({ queryKey: [key] })
    },
  })
}

export const bonusActions = {
  credit: ({ staffId, ...body }: { staffId: number; amount: number; reason: string }) => api.post<Data<BonusLedger>>(`admin/staff/${staffId}/bonus/credits`, body),
  reverse: ({ id, reason }: { id: number; reason: string }) => api.post<Data<BonusLedger>>(`admin/bonus-entries/${id}/reverse`, { reason }),
  approve: ({ id, note }: { id: number; note: string }) => api.post<Data<Withdrawal>>(`admin/bonus-withdrawals/${id}/approve`, { note: note || null }),
  reject: ({ id, note }: { id: number; note: string }) => api.post<Data<Withdrawal>>(`admin/bonus-withdrawals/${id}/reject`, { note }),
  /** Multipart: the receipt goes with it. */
  pay: ({ id, form }: { id: number; form: FormData }) => api.post<Data<Withdrawal>>(`admin/bonus-withdrawals/${id}/pay`, form),
  request: (body: { amount: number; note: string }) => api.post<Data<MyCommission>>('admin/profile/commission/withdrawals', { amount: body.amount, note: body.note || null }),
  cancel: (id: number) => api.post<Data<MyCommission>>(`admin/profile/commission/withdrawals/${id}/cancel`),
}
