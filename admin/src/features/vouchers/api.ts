import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { useToast } from '../../components/ui/feedback'
import { api, fetchDocument, upload } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/** docs/booking-vouchers.md — api/app/Http/Controllers/Api/V1/Admin/BookingVoucherController.php. */
export const VOUCHER_VIEWS = ['upcoming', 'past', 'archived'] as const
export type VoucherView = (typeof VOUCHER_VIEWS)[number]

export type Voucher = {
  id: number
  title: string
  booking: { id: number; reference: string } | null
  /** YYYY-MM-DD: check-in or travel date, when there is one. */
  service_date: string | null
  mime: 'application/pdf' | 'image/jpeg' | string
  bytes: number
  original_name: string
  uploaded_by: string | null
  uploaded_at: string
  archived_at: string | null
  archived_by: string | null
  archive_reason: string | null
  /** When the current file arrived: the upload, or the latest replacement. */
  file_uploaded_at: string
  /** Files it had before staff replaced them, newest first; kept and still opened (docs/booking-vouchers.md §6). */
  earlier_files: EarlierVoucherFile[]
}

export type EarlierVoucherFile = {
  id: number
  mime: string
  bytes: number
  original_name: string
  uploaded_by: string | null
  uploaded_at: string | null
  replaced_by: string | null
  replaced_at: string
}

/** A choice in the booking box: GET admin/vouchers/bookings. */
export type VoucherBookingHit = { id: number; reference: string; customer: string | null; title: string | null; travel_start: string | null }

/** "PDF", "JPG", "PNG" for the list's badge. */
export function fileKind(mime: string): 'PDF' | 'PNG' | 'JPG' {
  return mime === 'application/pdf' ? 'PDF' : mime === 'image/png' ? 'PNG' : 'JPG'
}

/** What the file inputs take: PDF, JPG or PNG (PNG from 2026-10-03). */
export const VOUCHER_ACCEPT = 'application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png'

export type VoucherFilters = { view: VoucherView; search: string; page: number }

type VoucherList = { data: Voucher[]; meta: { current_page: number; last_page: number; total: number; counts: Record<VoucherView, number> } }

/** "240 KB", "3.4 MB". */
export function fileSize(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

/** Matches BookingVouchers::MAX_KB in the API. */
export const VOUCHER_MAX_BYTES = 10 * 1024 * 1024

export function useVouchers({ view, search, page }: VoucherFilters) {
  const params = new URLSearchParams({ view, page: String(page) })
  if (search.trim()) params.set('search', search.trim())
  return useQuery({
    queryKey: ['vouchers', params.toString()],
    queryFn: ({ signal }) => api.get<VoucherList>(`admin/vouchers?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}

export type VoucherFields = { title: string; booking_reference: string; service_date: string; file: File }

/** Every change refreshes the list and any booking page that shows vouchers. */
function useVoucherMutation<TVariables, TResult>(send: (variables: TVariables) => Promise<TResult>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: send,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['vouchers'] })
      void client.invalidateQueries({ queryKey: ['booking'] })
    },
  })
}

export const useUploadVoucher = () =>
  useVoucherMutation(({ file, ...fields }: VoucherFields) => {
    const body = new FormData()
    for (const [key, value] of Object.entries(fields)) if (value.trim() !== '') body.append(key, value.trim())
    body.append('file', file)
    return upload<Data<Voucher>>('admin/vouchers', body, () => undefined)
  })

export type VoucherEdit = { id: number; title: string; booking_reference: string; service_date: string; file: File | null }

/**
 * Edits a voucher: title, booking (empty unlinks it), service date, and a new file when one is chosen; the old file is
 * kept as an earlier file. Multipart, so it posts with _method=PUT.
 */
export const useUpdateVoucher = () =>
  useVoucherMutation(({ id, file, ...fields }: VoucherEdit) => {
    const body = new FormData()
    body.append('_method', 'PUT')
    for (const [key, value] of Object.entries(fields)) body.append(key, value.trim())
    if (file) body.append('file', file)
    return upload<Data<Voucher>>(`admin/vouchers/${id}`, body, () => undefined)
  })

/** The booking box: bookings this staff member may see, by number, customer name or phone; the newest when empty. */
export function useVoucherBookings(search: string, enabled: boolean) {
  const term = search.trim()
  return useQuery({
    queryKey: ['voucher-bookings', term],
    queryFn: ({ signal }) => api.get<Data<VoucherBookingHit[]>>(`admin/vouchers/bookings?${new URLSearchParams(term ? { search: term } : {})}`, signal),
    enabled,
    placeholderData: (previous) => previous,
    staleTime: 30_000,
  })
}

export const useArchiveVoucher = () => useVoucherMutation(({ id, reason }: { id: number; reason: string }) => api.post<Data<Voucher>>(`admin/vouchers/${id}/archive`, { reason }))

/**
 * Opens a voucher in a new tab (a PDF in the browser's viewer, a photo as a picture), or saves it under the name it was
 * uploaded with. The file comes through the API with the staff token, decrypted and never cached, so it can't be a plain
 * link.
 */
export function useVoucherFile() {
  const { t } = useTranslation()
  const toast = useToast()
  // The voucher's file, or one of its earlier files.
  const path = (voucher: Voucher, earlier?: EarlierVoucherFile) => (earlier ? `admin/vouchers/${voucher.id}/earlier-files/${earlier.id}` : `admin/vouchers/${voucher.id}/file`)
  return {
    open: async (voucher: Voucher, earlier?: EarlierVoucherFile) => {
      // Opened before the request so the browser treats it as the click, not a pop-up.
      const tab = window.open('', '_blank')
      try {
        const url = URL.createObjectURL(await fetchDocument(path(voucher, earlier)))
        if (tab) tab.location.href = url
        else window.open(url, '_blank', 'noopener')
        setTimeout(() => URL.revokeObjectURL(url), 60_000)
      } catch {
        tab?.close()
        toast(t('vouchers.openFailed'), 'error')
      }
    },
    download: async (voucher: Voucher, earlier?: EarlierVoucherFile) => {
      try {
        const url = URL.createObjectURL(await fetchDocument(`${path(voucher, earlier)}?download=1`))
        const link = document.createElement('a')
        link.href = url
        link.download = (earlier ?? voucher).original_name
        document.body.append(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 60_000)
      } catch {
        toast(t('vouchers.openFailed'), 'error')
      }
    },
  }
}
