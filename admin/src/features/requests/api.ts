import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'
import type { NotificationGroup } from '../notifications/api'

/** The quotation-request queues: Air ticketing and Hotel requests (api/app/Http/Controllers/Api/V1/Admin/Concerns/WorksQuoteRequests.php). */
export type RequestKind = 'air' | 'hotel'

const PATH: Record<RequestKind, string> = { air: 'air-inquiries', hotel: 'hotel-inquiries' }

/** api/app/Http/Resources/AdminQuoteRequest.php */
export type QuoteRequest = {
  id: number
  type: 'air_quote' | 'hotel_quote'
  name: string
  phone: string
  email: string | null
  locale: 'bn' | 'en'
  status: 'new' | 'quoted'
  created_at: string
  age_hours: number
  /** Open and waiting over 24 hours — what the sidebar badge counts. */
  stale: boolean
  quoted_at: string | null
  quoted_by: { id: number; name: string } | null
  assigned_staff: { id: number; name: string } | null
  customer_id: number | null
  replies_count: number
  last_reply_at: string | null
  actions: Record<'claim' | 'mark_quoted' | 'undo_quoted' | 'assign' | 'reply', boolean>
}

/** api/app/Http/Resources/AdminAirInquiry.php */
export type AirInquiry = QuoteRequest & {
  from: string | null
  to: string | null
  depart_on: string | null
  return_on: string | null
  cabin_class: 'economy' | 'premium' | 'business' | 'first' | null
  passengers: number | null
}

/** api/app/Http/Resources/AdminHotelInquiry.php */
export type HotelInquiry = QuoteRequest & {
  location: string | null
  check_in: string | null
  check_out: string | null
  hotel_category: '3' | '4' | '5' | null
  guests: number | null
  note: string | null
}

/** GET <queue>/{id}: the row, every message about it, and what the reply box needs to know. */
export type RequestDetail<T extends QuoteRequest> = T & {
  notification_groups: NotificationGroup[]
  notifications_number_published: boolean
  notifications_sender_line: string
  /** The WhatsApp reply template filled for this request, with {{reply}} where the typed text goes. */
  reply_template: string
  customer_opted_out: boolean
}

export type RequestFilters = { state: 'open' | 'quoted' | 'all'; stale: boolean; owner: 'all' | 'mine' | 'pool'; search: string; page: number }

export function useRequests<T extends QuoteRequest>(kind: RequestKind, filters: RequestFilters) {
  const params = new URLSearchParams({ state: filters.state, page: String(filters.page) })
  if (filters.stale) params.set('stale', '1')
  if (filters.owner !== 'all') params.set('owner', filters.owner)
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: [PATH[kind], params.toString()],
    queryFn: ({ signal }) => api.get<Paginated<T>>(`admin/${PATH[kind]}?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useRequestDetail<T extends QuoteRequest>(kind: RequestKind, id: number) {
  return useQuery({ queryKey: [PATH[kind], 'detail', id], queryFn: ({ signal }) => api.get<Data<RequestDetail<T>>>(`admin/${PATH[kind]}/${id}`, signal) })
}

/** Every action answers with the request; the queue and the open detail refresh. */
export function useRequestAction<TVariables, TResult>(kind: RequestKind, send: (variables: TVariables) => Promise<TResult>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: () => void client.invalidateQueries({ queryKey: [PATH[kind]] }) })
}

export const requestActions = (kind: RequestKind) => ({
  claim: (id: number) => api.post<Data<QuoteRequest>>(`admin/${PATH[kind]}/${id}/claim`),
  markQuoted: (id: number) => api.post<Data<QuoteRequest>>(`admin/${PATH[kind]}/${id}/quoted`),
  undoQuoted: (id: number) => api.delete<Data<QuoteRequest>>(`admin/${PATH[kind]}/${id}/quoted`),
  reply: ({ id, text, markQuoted }: { id: number; text: string; markQuoted: boolean }) =>
    api.post<Data<RequestDetail<QuoteRequest>>>(`admin/${PATH[kind]}/${id}/reply`, { text, mark_quoted: markQuoted }),
})
