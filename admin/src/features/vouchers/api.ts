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
}

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

export const useArchiveVoucher = () => useVoucherMutation(({ id, reason }: { id: number; reason: string }) => api.post<Data<Voucher>>(`admin/vouchers/${id}/archive`, { reason }))

/**
 * Opens a voucher in a new tab (a PDF in the browser's viewer, a photo as a picture), or saves it under the name it was
 * uploaded with. The file comes through the API with the staff token, decrypted and never cached, so it can't be a plain
 * link.
 */
export function useVoucherFile() {
  const { t } = useTranslation()
  const toast = useToast()
  return {
    open: async (voucher: Voucher) => {
      // Opened before the request so the browser treats it as the click, not a pop-up.
      const tab = window.open('', '_blank')
      try {
        const url = URL.createObjectURL(await fetchDocument(`admin/vouchers/${voucher.id}/file`))
        if (tab) tab.location.href = url
        else window.open(url, '_blank', 'noopener')
        setTimeout(() => URL.revokeObjectURL(url), 60_000)
      } catch {
        tab?.close()
        toast(t('vouchers.openFailed'), 'error')
      }
    },
    download: async (voucher: Voucher) => {
      try {
        const url = URL.createObjectURL(await fetchDocument(`admin/vouchers/${voucher.id}/file?download=1`))
        const link = document.createElement('a')
        link.href = url
        link.download = voucher.original_name
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
