import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data, Paginated } from '../../lib/api/types'

/** api/app/Http/Controllers/Api/V1/Admin/DocumentReviewController.php (docs/phase-6-customer-portal.md §3.3). */
export type UploadKind = 'passport_scan' | 'photo'
export type IssuedKind = 'visa' | 'insurance'
export type ReviewStatus = 'uploaded' | 'rejected' | 'verified'
export type IssuedStatus = 'pending' | 'issued' | 'not_required'

export type DocumentReview = {
  id: number
  kind: UploadKind
  status: ReviewStatus
  /** booking: the scan uploaded with the website booking · portal · staff */
  source: 'booking' | 'portal' | 'staff' | null
  mime: string | null
  note: string | null
  uploaded_at: string | null
  reviewed_at: string | null
  reviewed_by: { id: number; name: string } | null
  traveller: { id: number; full_name: string; is_lead: boolean }
  booking: {
    id: number
    reference: string
    package_title_en: string
    travel_start: string | null
    status: string
    assigned_staff: { id: number; name: string } | null
  }
  actions: { review: boolean }
}

/** The slots on a booking's traveller (AdminBooking travellers[].documents). */
export type DocumentSlot = {
  id: number | null
  kind: UploadKind | IssuedKind
  status: 'missing' | ReviewStatus | IssuedStatus
  note: string | null
  uploadedAt: string | null
  reviewedAt: string | null
  hasFile: boolean
}

export type ReviewFilters = { status: ReviewStatus; page: number }

export function useDocumentReviews(filters: ReviewFilters) {
  const params = new URLSearchParams({ status: filters.status, page: String(filters.page) })
  return useQuery({
    queryKey: ['document-reviews', params.toString()],
    queryFn: ({ signal }) => api.get<Paginated<DocumentReview>>(`admin/document-reviews?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

/** Reviews refresh the queue and any open booking (its traveller chips show the same rows). */
export function useReviewDocument() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({ id, decision, reason }: { id: number; decision: 'verified' | 'rejected'; reason?: string }) =>
      api.post<Data<DocumentReview>>(`admin/traveller-documents/${id}/review`, { decision, reason: reason ?? null }),
    onSuccess: (response) => {
      void client.invalidateQueries({ queryKey: ['document-reviews'] })
      void client.invalidateQueries({ queryKey: ['booking', response.data.booking.id] })
    },
  })
}

export function useSetIssuedStatus(bookingId: number) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({ travellerId, kind, status, note }: { travellerId: number; kind: IssuedKind; status: IssuedStatus; note: string }) =>
      api.put<Data<DocumentSlot[]>>(`admin/booking-travellers/${travellerId}/documents/${kind}`, { status, note: note.trim() || null }),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['booking', bookingId] }),
  })
}

export const documentFilePath = (id: number) => `admin/traveller-documents/${id}/file`
