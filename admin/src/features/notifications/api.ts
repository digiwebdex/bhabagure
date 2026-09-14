import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** Shapes from api/app/Http/Resources/AdminNotification.php and Admin/NotificationController.php. */

export type NotificationChannel = 'whatsapp' | 'email' | 'sms'
export const CHANNELS: NotificationChannel[] = ['whatsapp', 'email', 'sms']
export type NotificationStatus = 'pending' | 'sending' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped' | 'cancelled'
export type AlertEvent = 'new_booking_alert' | 'new_lead_alert' | 'low_seat_alert' | 'quote_accepted_alert' | 'support_ticket_alert' | 'nps_follow_up_alert' | 'staff_document_expiring_alert'
export const ALERT_EVENTS: AlertEvent[] = ['new_booking_alert', 'new_lead_alert', 'low_seat_alert', 'quote_accepted_alert', 'support_ticket_alert', 'nps_follow_up_alert', 'staff_document_expiring_alert']

export type NotificationMessage = {
  id: number
  event: string
  channel: NotificationChannel
  status: NotificationStatus
  /** Masked for WhatsApp (01711•••344); the full number never reaches the admin. */
  to: string
  recipient_type: string | null
  /** Shared by the WhatsApp, email and SMS rows of one message. */
  group_key: string | null
  /** The WhatsApp message this SMS stands in for. */
  fallback_of_id: number | null
  sms_parts: number | null
  sms_encoding: 'gsm7' | 'ucs2' | null
  /** Estimated BDT when sent: SMS parts × rate; WhatsApp and email 0; null while not sent. */
  cost: number | null
  related: { type: string; id: number } | null
  title: string | null
  body: string
  has_attachment: boolean
  attempts: number
  last_error: string | null
  skipped_reason: string | null
  scheduled_for: string | null
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  failed_at: string | null
  triggered_by_staff_id: number | null
  created_at: string
}

export type NotificationTemplate = {
  id: number
  event: string
  channel: NotificationChannel
  audience: 'customer' | 'staff'
  timing: string
  variables: string[]
  subject_bn: string | null
  subject_en: string | null
  body_bn: string
  body_en: string
  is_enabled: boolean
  updated_at: string | null
}

export type ChannelCost = { messages: number; parts: number; cost: number }
export type MonthlyCost = { month: string; channels: Record<NotificationChannel, ChannelCost> }

export type NotificationOverview = {
  sms: {
    mode: 'live' | 'fake' | 'off'
    provider: string
    disabled_reason: string | null
    sender_id: string | null
    key_hint: string | null
    https: boolean
    cost_per_part: number
    warn_parts: number
    max_parts: number
  }
  costs: { this_month: MonthlyCost; last_month: MonthlyCost }
  whatsapp: {
    mode: 'live' | 'fake' | 'off'
    provider: string
    session: { status: string; checkedAt: string | null }
    key_hint: string | null
    webhook_configured: boolean
    seconds_between_sends: number
    daily_cap: number
  }
  email: { enabled: boolean; mailer: string; from: string | null }
  numbers: { main: string | null; notifications: string | null }
  counts: { pending: number; sent_today: number; failed_24h: number }
}

export type AlertSettings = {
  alert_recipients: Record<AlertEvent, number[]>
  eligible_staff: { id: number; name: string; role: string | null; role_name_en: string | null; role_name_bn: string | null; whatsapp: string }[]
}

export type SmsEstimate = { encoding: 'gsm7' | 'ucs2'; units: number; parts: number; per_part: number; cost: number; warn: boolean; too_long: boolean }

export type TemplatePreview = { body: string | null; subject: string | null; sms: SmsEstimate | null; unknown_variables: string[]; sample: string | null }

/** One message about a booking: each channel's own row and status, never a combined state. */
export type NotificationGroup = {
  group_key: string
  event: string
  recipient: { type: string | null; name: string | null }
  created_at: string | null
  channels: Record<NotificationChannel, NotificationMessage | null>
}

export type MyWhatsApp = { number: string | null; verified: boolean; verified_at: string | null; code_pending: boolean }

export type LogFilters = { status: NotificationStatus | 'all'; channel: NotificationChannel | 'all'; page: number }

export function useOverview() {
  return useQuery({ queryKey: ['notifications', 'overview'], queryFn: ({ signal }) => api.get<Data<NotificationOverview>>('admin/notifications/overview', signal) })
}

/** Asks WaSender for the session status now, instead of the last one a webhook reported. */
export function useCheckConnection() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => api.get<Data<NotificationOverview>>('admin/notifications/overview?refresh=1'),
    onSuccess: (response) => client.setQueryData(['notifications', 'overview'], response),
  })
}

export function useTemplates() {
  return useQuery({ queryKey: ['notifications', 'templates'], queryFn: ({ signal }) => api.get<Data<NotificationTemplate[]>>('admin/notification-templates', signal) })
}

export function useSaveTemplate(id: number) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (body: Pick<NotificationTemplate, 'body_bn' | 'body_en' | 'subject_bn' | 'subject_en' | 'is_enabled'>) => api.put<Data<NotificationTemplate>>(`admin/notification-templates/${id}`, body),
    onSuccess: (response) =>
      client.setQueryData<Data<NotificationTemplate[]>>(['notifications', 'templates'], (current) =>
        current ? { data: current.data.map((template) => (template.id === id ? response.data : template)) } : current,
      ),
  })
}

export function useTestTemplate(id: number) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (locale: 'bn' | 'en') => api.post<Data<NotificationMessage>>(`admin/notification-templates/${id}/test`, { locale }),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['notifications', 'log'] }),
  })
}

/** The server renders the preview (sender line, real values), so it is exactly what would be sent. */
export function usePreview(id: number, locale: 'bn' | 'en', body: string, subject: string | null) {
  return useQuery({
    queryKey: ['notifications', 'preview', id, locale, body, subject],
    queryFn: () => api.post<Data<TemplatePreview>>(`admin/notification-templates/${id}/preview`, { locale, body, subject }),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
}

export function useAlertSettings() {
  return useQuery({ queryKey: ['notifications', 'settings'], queryFn: ({ signal }) => api.get<Data<AlertSettings>>('admin/notification-settings', signal) })
}

export function useSaveAlertSettings() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (alert_recipients: Record<AlertEvent, number[]>) => api.put<Data<AlertSettings>>('admin/notification-settings', { alert_recipients }),
    onSuccess: (response) => client.setQueryData(['notifications', 'settings'], response),
  })
}

export function useNotificationLog({ status, channel, page }: LogFilters) {
  const params = new URLSearchParams({ page: String(page) })
  if (status !== 'all') params.set('status', status)
  if (channel !== 'all') params.set('channel', channel)
  return useQuery({
    queryKey: ['notifications', 'log', status, channel, page],
    queryFn: ({ signal }) => api.get<Paginated<NotificationMessage>>(`admin/notifications?${params}`, signal),
    placeholderData: keepPreviousData,
  })
}

export function useMyWhatsApp() {
  return useQuery({ queryKey: ['profile', 'whatsapp'], queryFn: ({ signal }) => api.get<Data<MyWhatsApp>>('admin/profile/whatsapp', signal) })
}

export function useMyWhatsAppAction<TVariables>(send: (variables: TVariables) => Promise<Data<MyWhatsApp>>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: (response) => {
      client.setQueryData(['profile', 'whatsapp'], response)
      void client.invalidateQueries({ queryKey: ['notifications', 'settings'] })
    },
  })
}

export const myWhatsAppActions = {
  start: (number: string) => api.post<Data<MyWhatsApp>>('admin/profile/whatsapp', { number }),
  verify: (code: string) => api.post<Data<MyWhatsApp>>('admin/profile/whatsapp/verify', { code }),
  remove: () => api.delete<Data<MyWhatsApp>>('admin/profile/whatsapp'),
}

/** Staff messages: the API looks the number up from the booking — nothing here carries a phone number. */
export function sendBookingWhatsApp(body: { booking_id: number; text: string; attach_invoice: boolean }) {
  return api.post<Data<NotificationMessage>>('admin/notifications/whatsapp', body)
}

/** A staff message to a customer from their profile (the portal invite): the number comes from the record. */
export function sendCustomerWhatsApp(body: { customer_id: number; text: string }) {
  return api.post<Data<NotificationMessage>>('admin/notifications/whatsapp', body)
}

export function setCustomerOptOut(customerId: number, optedOut: boolean) {
  return api.post<Data<{ customer_id: number; whatsapp_opted_out: boolean }>>(`admin/customers/${customerId}/whatsapp-opt-out`, { opted_out: optedOut })
}
