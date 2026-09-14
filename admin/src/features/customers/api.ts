import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'
import type { BookingSummary } from '../bookings/api'
import type { QuotationRow } from '../quotations/api'

export type LeadState = 'new' | 'contacted' | 'quoted' | 'converted' | 'lost'
export type PassportStatus = 'on_file' | 'expiring' | 'missing'

/** api/app/Http/Resources/AdminCustomer.php — passport numbers never come to the admin list; only a status. */
export type CustomerRow = {
  id: number
  name: string
  phone: string
  email: string | null
  stage: 'lead' | 'customer'
  source: string
  interest: string | null
  lead_state: LeadState
  lost_reason: string | null
  assigned_staff: { id: number; name: string } | null
  claimable: boolean
  created_at: string
  age_minutes: number
  last_contact_at: string | null
  next_follow_up_at: string | null
  follow_up_overdue: boolean
  passport_status: PassportStatus
  trips_completed: number
  next_trip: { booking_id: number; travel_start: string } | null
  whatsapp_opted_out: boolean
  has_bookings: boolean
  has_quotations: boolean
  actions: Record<'claim' | 'edit' | 'log_contact' | 'mark_lost' | 'assign', boolean>
}

export type ContactEntry = {
  id: number
  channel: string
  outcome: string
  note: string | null
  next_follow_up_at: string | null
  occurred_at: string
  staff: { id: number; name: string } | null
}

export type CustomerDetail = CustomerRow & { address: string | null; notes: string | null; locale: 'bn' | 'en'; contacts: ContactEntry[]; bookings: BookingSummary[]; quotations: QuotationRow[] }

export type BoardColumn = { count: number; cards: CustomerRow[] }
export type Board = Record<'new' | 'contacted' | 'quoted' | 'converted', BoardColumn>

export type CustomerFilters = { stage: 'all' | 'lead' | 'customer'; state: LeadState | 'all'; owner: 'all' | 'mine' | 'pool'; passport: 'all' | 'missing' | 'expiring'; search: string; page: number }

export const CONTACT_CHANNELS = ['call', 'whatsapp', 'facebook', 'visit', 'email', 'sms'] as const
export const CONTACT_OUTCOMES = ['reached', 'no_answer', 'interested', 'not_interested', 'call_back', 'quote_requested'] as const

export function useCustomers(filters: CustomerFilters) {
  const params = new URLSearchParams({ page: String(filters.page) })
  if (filters.stage !== 'all') params.set('stage', filters.stage)
  if (filters.state !== 'all') params.set('state', filters.state)
  if (filters.owner !== 'all') params.set('owner', filters.owner)
  if (filters.passport !== 'all') params.set('passport', filters.passport)
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: ['customers', filters.stage, filters.state, filters.owner, filters.passport, filters.search.trim(), filters.page],
    queryFn: ({ signal }) => api.get<Paginated<CustomerRow>>(`admin/customers?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useBoard(owner: CustomerFilters['owner']) {
  return useQuery({
    queryKey: ['customers', 'board', owner],
    queryFn: ({ signal }) => api.get<Data<Board>>(`admin/customers/board${owner === 'all' ? '' : `?owner=${owner}`}`, signal).then((r) => r.data),
  })
}

export function useCustomer(id: number, enabled = true) {
  return useQuery({ queryKey: ['customer', id], queryFn: ({ signal }) => api.get<Data<CustomerDetail>>(`admin/customers/${id}`, signal), enabled })
}

/** Every customer action answers with the updated profile; lists and the board refresh. */
export function useCustomerAction<TVariables>(send: (variables: TVariables) => Promise<Data<CustomerDetail>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      client.setQueryData(['customer', response.data.id], response)
      void client.invalidateQueries({ queryKey: ['customers'] })
    },
  })
}

export const customerActions = {
  create: (body: { name: string; phone: string; email: string | null; source: string; interest: string | null }) => api.post<Data<CustomerDetail>>('admin/customers', body),
  update: (id: number) => (body: Partial<Pick<CustomerDetail, 'name' | 'email' | 'interest' | 'address' | 'notes'>>) => api.put<Data<CustomerDetail>>(`admin/customers/${id}`, body),
  contact: (id: number) => (body: { channel: string; outcome: string; note: string | null; next_follow_up_at: string | null }) => api.post<Data<CustomerDetail>>(`admin/customers/${id}/contacts`, body),
  lost: (id: number) => (reason: string) => api.post<Data<CustomerDetail>>(`admin/customers/${id}/lost`, { reason }),
  reopen: (id: number) => () => api.delete<Data<CustomerDetail>>(`admin/customers/${id}/lost`),
  claim: (id: number) => api.post<Data<CustomerDetail>>(`admin/customers/${id}/claim`),
}

export function useDeleteCustomer() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api.delete<null>(`admin/customers/${id}`),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['customers'] }),
  })
}
