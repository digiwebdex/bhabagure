import { useQuery } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'

/** api/app/Http/Controllers/Api/V1/Admin/DownloadLogController.php */
export type DownloadRow = {
  id: number
  created_at: string
  kind: 'package' | 'visa'
  /** The package or visa as it was named when downloaded (English). */
  title: string
  /** The website slug, while the package or visa still exists. */
  slug: string | null
  hotel_category: '3' | '4' | '5' | null
  pax: number | null
  locale: 'bn' | 'en'
  customer: { id: number; name: string; phone: string; email: string | null; stage: 'lead' | 'customer'; owner: string | null; downloads: number }
  /** Whether the customer already has an inquiry or confirmed booking, or an open or draft quotation. */
  in_progress: { booking: boolean; quotation: boolean }
}

export type DownloadTotals = { downloads: number; customers: number; packages: number; visas: number }

export type DownloadFilters = { kind: 'all' | 'package' | 'visa'; followUp: boolean; search: string; from: string; to: string; page: number }

export function useDownloads(filters: DownloadFilters) {
  const params = new URLSearchParams({ page: String(filters.page) })
  if (filters.kind !== 'all') params.set('kind', filters.kind)
  if (filters.followUp) params.set('follow_up', '1')
  if (filters.search.trim()) params.set('search', filters.search.trim())
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  return useQuery({
    queryKey: ['downloads', params.toString()],
    queryFn: ({ signal }) => api.get<Paginated<DownloadRow> & { meta: { totals: DownloadTotals } }>(`admin/downloads?${params}`, signal),
    placeholderData: (previous) => previous,
  })
}
