import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api, type Data } from './api'

/** Shapes from api/app/Wallet/Http/Controllers (docs/phase-7-hr-attendance-bonus-wallet.md §8). */

export type Direction = 'in' | 'out'

export type Me = { name: string; email: string; locale: string }

export type Summary = {
  balance: number
  total_in: number
  total_out: number
  count_in: number
  count_out: number
  month_in: number
  last_entry_at: string | null
  by_source: { name: string; in: number; out: number }[]
  due_total: number
}

export type Entry = {
  id: number
  direction: Direction
  amount: number
  kind: 'entry' | 'deal_advance' | 'deal_payment' | 'reversal'
  source: string
  deal: { id: number; name: string | null } | null
  reference: string
  reason: string | null
  occurred_on: string
  has_evidence: boolean
  reverses_id: number | null
  reversed: boolean
  created_at: string | null
}

export type Deal = {
  id: number
  name: string
  total: number
  note: string | null
  received: number
  due: number
  settled: boolean
  created_at: string | null
  payments: { id: number; kind: Entry['kind']; amount: number; reference: string; occurred_on: string; reversed: boolean }[]
}

export type Source = { id: number; name: string }
export type Preset = { id: number; direction: Direction; label: string }

export type PasswordStep = { challenge: string; enrolled: boolean; enrollment: { secret: string; uri: string } | null }

export function useMe() {
  return useQuery({ queryKey: ['me'], queryFn: ({ signal }) => api.get<Data<Me>>('auth/me', signal).then((r) => r.data), retry: false })
}

export const useSummary = () => useQuery({ queryKey: ['summary'], queryFn: ({ signal }) => api.get<Data<Summary>>('summary', signal).then((r) => r.data) })
export const useSources = () => useQuery({ queryKey: ['sources'], queryFn: ({ signal }) => api.get<Data<Source[]>>('sources', signal).then((r) => r.data) })
export const usePresets = () => useQuery({ queryKey: ['presets'], queryFn: ({ signal }) => api.get<Data<Preset[]>>('presets', signal).then((r) => r.data) })
export const useDeals = () => useQuery({ queryKey: ['deals'], queryFn: ({ signal }) => api.get<Data<Deal[]>>('deals', signal).then((r) => r.data) })

export function useHistory(direction: Direction | 'all', page: number) {
  return useQuery({
    queryKey: ['history', direction, page],
    queryFn: ({ signal }) => api.get<Data<Entry[]> & { meta: { current_page: number; last_page: number; total: number } }>(`transactions?direction=${direction}&page=${page}`, signal),
    placeholderData: (previous) => previous,
  })
}

/** Money moved: the balance, breakdown, deals and history all follow. */
export function useBookChange<TVariables, TResponse>(send: (variables: TVariables) => Promise<TResponse>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      for (const key of ['summary', 'deals', 'history', 'sources', 'presets']) void client.invalidateQueries({ queryKey: [key] })
    },
  })
}

export const actions = {
  password: (body: { email: string; password: string }) => api.post<Data<PasswordStep>>('auth/password', body).then((r) => r.data),
  code: (body: { challenge: string; code: string }) => api.post<Data<{ signed_in: boolean }>>('auth/code', body),
  signOut: () => api.post<Data<{ signed_in: boolean }>>('auth/sign-out'),
  record: (form: FormData) => api.post<Data<Entry>>('transactions', form),
  reverse: ({ id, reason }: { id: number; reason: string }) => api.post<Data<Entry>>(`transactions/${id}/reverse`, { reason }),
  createDeal: (form: FormData) => api.post<Data<Deal>>('deals', form),
  pay: ({ id, form }: { id: number; form: FormData }) => api.post<Data<Deal>>(`deals/${id}/payments`, form),
  addSource: (name: string) => api.post<Data<Source[]>>('sources', { name }),
  archiveSource: (id: number) => api.post<Data<Source[]>>(`sources/${id}/archive`),
  addPreset: (body: { direction: Direction; label: string }) => api.post<Data<Preset[]>>('presets', body),
  removePreset: (id: number) => api.post<Data<Preset[]>>(`presets/${id}/remove`),
}
