import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api, fetchDocument, upload } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** api/app/Http/Controllers/Api/V1/Admin/InboxController.php (docs/admin-inbox.md). */
export type Channel = 'whatsapp' | 'messenger'
export type InboxView = 'all' | 'unread' | 'mine' | 'closed'

export type ConversationRow = {
  id: number
  channel: Channel
  name: string | null
  phone: string | null
  status: 'open' | 'closed'
  unread_count: number
  last_message_at: string | null
  last_message_preview: string | null
  last_message_direction: 'in' | 'out' | null
  assignee: { id: number; name: string } | null
  customer_id: number | null
}

export type InboxMessage = {
  id: number
  direction: 'in' | 'out'
  /** customer · staff here · someone on the phone or in the Page inbox · an automated message */
  origin: 'customer' | 'staff' | 'phone' | 'automated'
  staff: { id: number; name: string } | null
  body: string | null
  attachment: { kind: 'image' | 'document' | 'audio' | 'video' | 'sticker'; name: string | null; mime: string | null; bytes: number | null; ready: boolean; unavailable: boolean } | null
  status: 'received' | 'pending' | 'sent' | 'delivered' | 'read' | 'failed'
  error: string | null
  sent_at: string
}

export type LinkedCustomer =
  | { id: number; visible: false }
  | {
      id: number
      visible: true
      name: string
      phone: string
      email: string | null
      stage: string
      bookings: { id: number; reference: string; package_title: string | null; travel_start: string | null; status: string; total: number }[]
    }

export type ConversationDetail = ConversationRow & {
  can_reply: boolean
  reply_window_ends_at: string | null
  customer: LinkedCustomer | null
  messages: InboxMessage[]
  has_older: boolean
  /** Who the chat can be handed to; null for those who may only take it themselves. */
  assignees: { id: number; name: string }[] | null
}

export type ConversationList = Paginated<ConversationRow> & {
  meta: Paginated<ConversationRow>['meta'] & { counts: Record<InboxView, number>; unread_by_channel: Record<Channel, number> }
}

export type InboxFilters = { view: InboxView; channel: Channel | 'all'; search: string; page: number }

export type CannedReply = { id: number; title: string; body: string; sort_order: number }

export type InboxSettings = {
  whatsapp: { enabled: boolean; configured: boolean; webhook_secret_set: boolean; session_status: string | null; webhook_url: string; events: string[] }
  messenger: {
    connected: boolean
    page_id: string | null
    page_name: string | null
    connected_at: string | null
    app_secret_set: boolean
    verify_token: string
    webhook_url: string
    fields: string[]
  }
}

/** The list refreshes every 10 seconds, so new chats appear without reloading. */
export function useConversations(filters: InboxFilters) {
  const params = new URLSearchParams({ view: filters.view, page: String(filters.page) })
  if (filters.channel !== 'all') params.set('channel', filters.channel)
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: ['inbox', 'list', params.toString()],
    queryFn: ({ signal }) => api.get<ConversationList>(`admin/inbox/conversations?${params}`, signal),
    placeholderData: (previous) => previous,
    refetchInterval: 10_000,
  })
}

/** The open chat refreshes every 5 seconds while it is on screen. */
export function useConversation(id: number | null) {
  return useQuery({
    queryKey: ['inbox', 'conversation', id],
    queryFn: ({ signal }) => api.get<Data<ConversationDetail>>(`admin/inbox/conversations/${id}`, signal),
    enabled: id !== null,
    refetchInterval: 5_000,
  })
}

export const olderMessages = (id: number, before: number) => api.get<Data<ConversationDetail>>(`admin/inbox/conversations/${id}?before=${before}`)

export const useCannedReplies = () =>
  useQuery({ queryKey: ['inbox', 'canned'], queryFn: ({ signal }) => api.get<Data<CannedReply[]>>('admin/inbox/canned-replies', signal), staleTime: 60_000 })

function useInboxMutation<TVariables>(id: number, send: (variables: TVariables) => Promise<Data<ConversationDetail> | unknown>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['inbox', 'conversation', id] })
      void client.invalidateQueries({ queryKey: ['inbox', 'list'] })
      void client.invalidateQueries({ queryKey: ['nav-counts'] })
    },
  })
}

export const useReply = (id: number) =>
  useInboxMutation(id, ({ body, file }: { body: string; file: File | null }) => {
    const form = new FormData()
    if (body.trim() !== '') form.append('body', body)
    if (file) form.append('file', file)
    return upload<Data<InboxMessage>>(`admin/inbox/conversations/${id}/messages`, form, () => undefined)
  })

/** A started (or reopened) chat: `existing` when the number had a chat already. */
export type StartedChat = ConversationRow & { existing: boolean }

/** A WhatsApp chat with a number staff type in (docs/admin-inbox.md §8): the one already there, or a new one. */
export function useStartChat() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (chat: { phone: string; name: string; body: string }) => api.post<Data<StartedChat>>('admin/inbox/conversations', chat),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['inbox', 'list'] })
      void client.invalidateQueries({ queryKey: ['nav-counts'] })
    },
  })
}

export const useMarkRead = (id: number) => useInboxMutation(id, () => api.post(`admin/inbox/conversations/${id}/read`))
export const useAssign = (id: number) => useInboxMutation(id, (staffId: number | null) => api.post(`admin/inbox/conversations/${id}/assign`, { staff_id: staffId }))
export const useSetStatus = (id: number) => useInboxMutation(id, (action: 'close' | 'reopen') => api.post(`admin/inbox/conversations/${id}/${action}`))
export const useLinkCustomer = (id: number) => useInboxMutation(id, (customerId: number | null) => api.post(`admin/inbox/conversations/${id}/customer`, { customer_id: customerId }))
export const useCreateLead = (id: number) => useInboxMutation(id, (lead: { name: string; phone: string }) => api.post(`admin/inbox/conversations/${id}/lead`, lead))

/**
 * An attachment as a blob URL: files come through the API with the staff token, decrypted and never cached, so they
 * can't be a plain <img src>. Fetched once per message while the page is open.
 */
export function useAttachmentUrl(message: InboxMessage, enabled: boolean) {
  return useQuery({
    queryKey: ['inbox', 'file', message.id],
    queryFn: async () => URL.createObjectURL(await fetchDocument(`admin/inbox/messages/${message.id}/file`)),
    enabled: enabled && !!message.attachment?.ready,
    staleTime: Infinity,
    gcTime: 10 * 60_000,
  })
}

export const useInboxSettings = () => useQuery({ queryKey: ['inbox', 'settings'], queryFn: ({ signal }) => api.get<Data<InboxSettings>>('admin/inbox/settings', signal) })

export function useSettingsMutation<TVariables>(send: (variables: TVariables) => Promise<Data<InboxSettings>>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: (response) => client.setQueryData(['inbox', 'settings'], response) })
}

export function useCannedMutation<TVariables>(send: (variables: TVariables) => Promise<unknown>) {
  const client = useQueryClient()
  return useMutation({ mutationFn: send, onSuccess: () => void client.invalidateQueries({ queryKey: ['inbox', 'canned'] }) })
}
