import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** api/app/Http/Controllers/Api/V1/Admin/PaymentsController.php — money is a number. */
export type MethodCard = { key: 'bkash' | 'nagad' | 'sslcommerz' | 'cash_bank' | 'rocket'; amount: number; payments: number }

export type MoneyAccount = { code: string; name_en: string; name_bn: string; balance: number; opening: { amount: number; as_of: string; by: string | null } | null }

export type Balance = { total: number; accounts: MoneyAccount[] }

export type PaymentsSummary = { month: string; methods: MethodCard[]; collected: number; invoiced: number; review_count: number; balance: Balance | null }

export type Party = { type: 'customer' | 'client'; id: number | null; name: string; phone: string | null; email: string | null }

export type CashEntry = {
  id: number
  occurred_at: string
  direction: 'in' | 'out'
  amount: number
  category: string
  business_line: string | null
  method: string
  description: string
  reference: string | null
  booking: { id: number; reference: string } | null
  invoice: { id: number; number: string | null; kind: 'booking' | 'deal'; title: string | null } | null
  party: Party | null
  recorded_by: { id: number; name: string } | null
  reverses_id: number | null
  reversed_by: { id: number; occurred_at: string } | null
  has_evidence: boolean
  /** The account the money sat in. Older entries carry only the code the method has always meant. */
  account: { code: string; name: string | null } | null
  /** Who has checked it, if anyone. The entry itself is never touched to record this. */
  approved: { at: string; by: string | null; note: string | null } | null
  /** An edit's corrected entry: what it replaced, and what changed (docs/transaction-edits.md). */
  edited: { from_id: number; at: string | null; by: string | null; changes: Record<string, [unknown, unknown]>; reason: string | null } | null
  /** An entry that was edited or deleted (in the list with "Show edits and deletions"). */
  correction: { kind: 'edit' | 'delete'; replacement_id: number | null; at: string | null; by: string | null; reason: string | null } | null
  actions: { edit: boolean; delete: boolean; approve: boolean }
  edit_blocked: CorrectionBlocked | null
  delete_blocked: CorrectionBlocked | null
}

/** Why Edit or Delete is unavailable (CashBookCorrections in the API). */
export type CorrectionBlocked = 'is_reversal' | 'reversed' | 'online' | 'vat' | 'fee_line' | 'payroll' | 'bonus' | 'charge' | 'permission'

/** What an edit sends (POST cash-book/{id}/edit). A customer payment keeps its category. */
export type CashEntryEdit = { occurred_on: string; account: string; category?: string; business_line?: string | null; description: string; reference: string | null; amount: number; reason?: string }

export type CashBookFilters = {
  direction: 'all' | 'in' | 'out'
  account: string
  category: string
  from: string
  to: string
  search: string
  staff_id: string
  approved: 'all' | 'yes' | 'no'
  /** Edited and deleted entries, with the entries that cancelled them. */
  history: boolean
  page: number
}

export type PaymentOptions = {
  methods: string[]
  categories: { in: string[]; out: string[] }
  business_lines: string[]
  money_accounts: { code: string; name_en: string; name_bn: string; has_opening_balance: boolean }[]
}

export type ReviewAttempt = {
  id: number
  tran_id: string
  status: string
  reason: string | null
  booking: { id: number; reference: string; customer: string | null }
  amount: number
  online_charge: number
  gateway_amount: number | null
  gateway_surcharge: number
  card_type: string | null
  risk_level: number | null
  at: string
}

export type Deal = {
  id: number
  number: string
  title: string
  note: string | null
  status: 'issued' | 'void'
  payment_status: 'unpaid' | 'partial' | 'paid'
  issued_on: string
  party: Party
  total_amount: number
  paid_amount: number
  balance_due: number
  issued_by: string | null
  void_reason: string | null
  payments: { id: number; occurred_at: string; direction: 'in' | 'out'; amount: number; method: string; description: string; reference: string | null; is_reversal: boolean }[]
}

export type ReferencePreset = { id: number; label: string; direction: 'in' | 'out' | null }

export function usePaymentsSummary(month: string) {
  return useQuery({ queryKey: ['payments', 'summary', month], queryFn: ({ signal }) => api.get<Data<PaymentsSummary>>(`admin/payments/summary?month=${month}`, signal).then((r) => r.data) })
}

export function usePaymentOptions() {
  return useQuery({ queryKey: ['payments', 'options'], queryFn: ({ signal }) => api.get<Data<PaymentOptions>>('admin/payments/options', signal).then((r) => r.data) })
}

export function useCashBook(filters: CashBookFilters) {
  const params = new URLSearchParams({ page: String(filters.page) })
  if (filters.direction !== 'all') params.set('direction', filters.direction)
  for (const key of ['account', 'category', 'from', 'to', 'staff_id'] as const) if (filters[key]) params.set(key, filters[key])
  if (filters.approved !== 'all') params.set('approved', filters.approved === 'yes' ? '1' : '0')
  if (filters.history) params.set('history', '1')
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: ['payments', 'cash-book', params.toString()],
    queryFn: ({ signal }) => api.get<Paginated<CashEntry>>(`admin/cash-book?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useReviewQueue(enabled: boolean) {
  return useQuery({ queryKey: ['payments', 'review'], queryFn: ({ signal }) => api.get<Data<ReviewAttempt[]>>('admin/payment-attempts/review', signal).then((r) => r.data), enabled })
}

export function useDeals(state: 'open' | 'paid' | 'void' | 'all') {
  return useQuery({
    queryKey: ['payments', 'deals', state],
    queryFn: ({ signal }) => api.get<Paginated<Deal> & { meta: { total_due: number } }>(`admin/deals?state=${state}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function usePresets() {
  return useQuery({ queryKey: ['payments', 'presets'], queryFn: ({ signal }) => api.get<Data<ReferencePreset[]>>('admin/reference-presets', signal).then((r) => r.data) })
}

/** Every money action changes the cards, the cash book, the balance and the deals: refresh them all. */
export function usePaymentsMutation<TVariables, TResult>(send: (variables: TVariables) => Promise<TResult>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: () => void client.invalidateQueries({ queryKey: ['payments'] }) })
}

export const paymentActions = {
  /** Manual cash in / out; multipart when a receipt is attached. */
  createEntry: (form: FormData) => api.post<Data<CashEntry>>('admin/cash-entries', form),
  /** Delete: the entry is cancelled by a reversing one on its own date (docs/transaction-edits.md). */
  reverse: (id: number, reason: string) => api.post<Data<CashEntry>>(`admin/cash-book/${id}/reverse`, { reason }),
  /** Edit: the entry is cancelled and the corrected one recorded; answers with the corrected entry. */
  edit: (id: number, body: CashEntryEdit) => api.post<Data<CashEntry>>(`admin/cash-book/${id}/edit`, body),
  review: (id: number, note: string) => api.post<null>(`admin/payment-attempts/${id}/review`, { note }),
  openingBalance: (body: { account: string; amount: number; as_of: string; note: string | null }) => api.post<Data<Balance>>('admin/opening-balances', body),
  /** Money moved between the company's own money accounts: no total changes, so it is a journal entry, not a cash entry. */
  transfer: (body: { from: string; to: string; amount: number; description: string; occurred_on: string }) => api.post<Data<Balance>>('admin/transfers', body),
  /** VAT collected from customers, handed over to the government; multipart, because its receipt goes with it. */
  payVat: (body: FormData) => api.post<Data<CashEntry>>('admin/vat-payments', body),
  /** Ticking off an entry someone has checked, or taking the tick back. The entry itself is never edited. */
  approve: (id: number, body: { approved: boolean; note?: string }) => api.post<Data<CashEntry>>(`admin/cash-book/${id}/approve`, body),
  addPreset: (body: { label: string; direction: 'in' | 'out' | null }) => api.post<Data<ReferencePreset>>('admin/reference-presets', body),
  removePreset: (id: number) => api.delete<null>(`admin/reference-presets/${id}`),
  /** Multipart when there is an advance: its receipt goes with it. */
  createDeal: (body: FormData) => api.post<Data<Deal>>('admin/deals', body),
  payDeal: (id: number, { evidence, ...fields }: { amount: number; method: string; reference: string | null; evidence: File; account?: string | null; occurred_on?: string }) => {
    const body = new FormData()
    for (const [key, value] of Object.entries(fields)) if (value !== null && value !== '') body.append(key, String(value))
    body.append('evidence', evidence)
    return api.post<Data<Deal>>(`admin/deals/${id}/payments`, body)
  },
  voidDeal: (id: number, reason: string) => api.post<Data<Deal>>(`admin/deals/${id}/void`, { reason }),
}
