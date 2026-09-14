import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** api/app/Http/Controllers/Api/V1/Admin/SupportTicketController.php (docs/phase-6-customer-portal.md §3.5). */
export type TicketStatus = 'open' | 'answered' | 'closed'

export type SupportTicket = {
  id: number
  number: string
  subject: string
  status: TicketStatus
  /** Open and waiting over 24 hours — what the sidebar badge counts. */
  overdue: boolean
  customer: { id: number; name: string; phone: string }
  booking: { id: number; reference: string; assigned_staff: { id: number; name: string } | null } | null
  messages_count: number
  last_customer_message_at: string
  last_staff_reply_at: string | null
  closed_at: string | null
  created_at: string
}

export type SupportTicketDetail = SupportTicket & {
  messages: { id: number; author: 'customer' | 'staff'; staff: { id: number; name: string } | null; body: string; created_at: string }[]
}

export const ticketTone = (ticket: Pick<SupportTicket, 'status' | 'overdue'>) =>
  ticket.status === 'closed' ? 'slate' : ticket.status === 'answered' ? 'green' : ticket.overdue ? 'red' : 'orange'

export type SupportFilters = { status: TicketStatus | 'all'; overdue: boolean; search: string; page: number }

export function useSupportTickets(filters: SupportFilters) {
  const params = new URLSearchParams({ status: filters.status, page: String(filters.page) })
  if (filters.overdue) params.set('overdue', '1')
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: ['support-tickets', params.toString()],
    queryFn: ({ signal }) => api.get<Paginated<SupportTicket>>(`admin/support-tickets?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export function useSupportTicket(id: number) {
  return useQuery({ queryKey: ['support-ticket', id], queryFn: ({ signal }) => api.get<Data<SupportTicketDetail>>(`admin/support-tickets/${id}`, signal) })
}

export function useSupportAction<TVariables>(id: number, send: (variables: TVariables) => Promise<Data<SupportTicketDetail>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      client.setQueryData(['support-ticket', id], response)
      void client.invalidateQueries({ queryKey: ['support-tickets'] })
    },
  })
}

export const supportActions = {
  reply: (id: number) => (body: string) => api.post<Data<SupportTicketDetail>>(`admin/support-tickets/${id}/replies`, { body }),
  close: (id: number) => () => api.post<Data<SupportTicketDetail>>(`admin/support-tickets/${id}/close`),
}
