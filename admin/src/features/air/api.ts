import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** api/app/Http/Resources/AdminAirInquiry.php */
export type AirInquiry = {
  id: number
  name: string
  phone: string
  email: string | null
  locale: 'bn' | 'en'
  from: string | null
  to: string | null
  depart_on: string | null
  return_on: string | null
  cabin_class: 'economy' | 'premium' | 'business' | 'first' | null
  passengers: number | null
  status: 'new' | 'quoted'
  created_at: string
  age_hours: number
  /** Open and waiting over 24 hours — what the sidebar badge counts. */
  stale: boolean
  quoted_at: string | null
  quoted_by: { id: number; name: string } | null
  assigned_staff: { id: number; name: string } | null
  customer_id: number | null
  actions: Record<'claim' | 'mark_quoted' | 'undo_quoted' | 'assign', boolean>
}

export type AirFilters = { state: 'open' | 'quoted' | 'all'; stale: boolean; owner: 'all' | 'mine' | 'pool'; search: string; page: number }

export function useAirInquiries(filters: AirFilters) {
  const params = new URLSearchParams({ state: filters.state, page: String(filters.page) })
  if (filters.stale) params.set('stale', '1')
  if (filters.owner !== 'all') params.set('owner', filters.owner)
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: ['air-inquiries', params.toString()],
    queryFn: ({ signal }) => api.get<Paginated<AirInquiry>>(`admin/air-inquiries?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useAirAction<TVariables>(send: (variables: TVariables) => Promise<Data<AirInquiry>>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: () => void client.invalidateQueries({ queryKey: ['air-inquiries'] }) })
}

export const airActions = {
  claim: (id: number) => api.post<Data<AirInquiry>>(`admin/air-inquiries/${id}/claim`),
  markQuoted: (id: number) => api.post<Data<AirInquiry>>(`admin/air-inquiries/${id}/quoted`),
  undoQuoted: (id: number) => api.delete<Data<AirInquiry>>(`admin/air-inquiries/${id}/quoted`),
}
