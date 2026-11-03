import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { TFunction } from 'i18next'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/**
 * What invoice lines sold (docs/invoice-services.md; api/app/Http/Controllers/Api/V1/Admin/ServiceRecordController.php):
 * the Tickets of Air ticketing and the Bookings of Hotel requests. The invoice shows and changes the same record.
 */
export type ServiceKind = 'air' | 'hotel'
export type ServiceType = 'air_ticket' | 'hotel_booking' | 'tour_package'
export type ServiceStatus = 'booked' | 'confirmed' | 'schedule_changed'
export type TripType = 'one_way' | 'round_trip'

export const SERVICE_TYPES: ServiceType[] = ['air_ticket', 'hotel_booking', 'tour_package']

/** What the invoice form edits, sent with the line. `line_key` keeps a record with its line through a correction. */
export type ServiceFields = {
  id?: number
  line_key?: string | null
  type: ServiceType
  passenger_name: string | null
  reference: string | null
  trip_type: TripType | null
  route_from: string | null
  route_to: string | null
  starts_on: string | null
  ends_on: string | null
  hotel_name: string | null
  room_type: string | null
  package_name: string | null
  tour_package_id: number | null
  status: ServiceStatus | null
  schedule_note?: string | null
}

/** api/app/Http/Resources/AdminServiceRecord.php */
export type ServiceRow = ServiceFields & {
  id: number
  customer_name: string | null
  phone: string | null
  email: string | null
  days_left: number | null
  departure_soon: boolean
  departure_urgent: boolean
  schedule_changed_at: string | null
  updated_by: string | null
  updated_at: string | null
  cancelled: boolean
  invoice: { id: number; number: string | null; status: string }
  /** The message the WhatsApp button opens ready to send, in the customer's language. */
  whatsapp_text: string
  can_update: boolean
}

export const SERVICE_FILTERS = {
  air: ['upcoming', 'soon', 'changed', 'past', 'cancelled', 'all'],
  hotel: ['upcoming', 'past', 'cancelled', 'all'],
} as const

export type ServiceFilter = 'upcoming' | 'soon' | 'changed' | 'past' | 'cancelled' | 'all'
export type ServiceFilters = { filter: ServiceFilter; search: string; page: number }

type ServiceList = Paginated<ServiceRow> & { meta: { counts: { soon: number; changed: number } } }

const KEY = 'service-records'

export function useServiceRecords(kind: ServiceKind, filters: ServiceFilters) {
  const params = new URLSearchParams({ kind, filter: filters.filter, page: String(filters.page) })
  if (filters.search.trim()) params.set('search', filters.search.trim())
  return useQuery({
    queryKey: [KEY, params.toString()],
    queryFn: ({ signal }) => api.get<ServiceList>(`admin/service-records?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export type ServiceUpdate = { status: ServiceStatus; reference: string | null; starts_on: string; ends_on: string | null; schedule_note: string | null }

/** Changed from Air ticketing, Hotel requests or the invoice: all three show it, and the next printed copy. */
export function useUpdateService() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: ServiceUpdate }) => api.put<Data<ServiceRow>>(`admin/service-records/${id}`, input),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: [KEY] })
      void client.invalidateQueries({ queryKey: ['invoice'] })
    },
  })
}

/** A line's starting details once it is said to be for something; a key it already has stays, so its record stays too. */
export function blankService(type: ServiceType, current: ServiceFields | null): ServiceFields {
  return {
    line_key: current?.line_key ?? null,
    type,
    passenger_name: current?.passenger_name ?? null,
    reference: type === 'tour_package' ? null : (current?.reference ?? null),
    trip_type: type === 'air_ticket' ? (current?.trip_type ?? 'round_trip') : null,
    route_from: type === 'air_ticket' ? (current?.route_from ?? 'Dhaka') : null,
    route_to: current?.route_to ?? null,
    starts_on: current?.starts_on ?? null,
    ends_on: current?.ends_on ?? null,
    hotel_name: current?.hotel_name ?? null,
    room_type: current?.room_type ?? null,
    package_name: current?.package_name ?? null,
    tour_package_id: current?.tour_package_id ?? null,
    status: type === 'tour_package' ? null : (current?.status ?? 'booked'),
  }
}

/** A picked package makes a plain line a Tour Package; a line already said to be a ticket or a hotel stays as it is. */
export function serviceForPickedPackage(current: ServiceFields | null, title: string, packageId: number): ServiceFields | null {
  if (current && current.type !== 'tour_package') return current
  return { ...blankService('tour_package', current), package_name: title, tour_package_id: packageId }
}

/** As sent: a box left empty is no value, not an empty one. */
export const cleanService = (service: ServiceFields): ServiceFields =>
  Object.fromEntries(Object.entries(service).map(([key, value]) => [key, typeof value === 'string' && value.trim() === '' ? null : value])) as ServiceFields

/** The record in a few words, as the printed invoice reads it. */
export function serviceSummary(service: ServiceFields, date: (iso: string) => string, t: TFunction): string {
  const on = (key: string, value: string | null) => (value ? t(`serviceRecords.printed.${key}`, { date: date(value) }) : null)
  const parts =
    service.type === 'air_ticket'
      ? [
          service.passenger_name,
          service.reference ? `PNR ${service.reference}` : null,
          `${service.route_from ?? '?'} → ${service.route_to ?? '?'}`,
          service.trip_type ? t(`serviceRecords.tripTypes.${service.trip_type}`) : null,
          on('departure', service.starts_on),
          on('return', service.ends_on),
        ]
      : service.type === 'hotel_booking'
        ? [service.passenger_name, service.hotel_name, service.room_type, service.reference, on('checkIn', service.starts_on), on('checkOut', service.ends_on)]
        : [service.package_name, on('departure', service.starts_on), on('return', service.ends_on)]
  return parts.filter(Boolean).join(' · ')
}

export const statusTone = (status: ServiceStatus | null): 'orange' | 'green' | 'red' | 'slate' =>
  status === 'confirmed' ? 'green' : status === 'schedule_changed' ? 'red' : status === 'booked' ? 'orange' : 'slate'
