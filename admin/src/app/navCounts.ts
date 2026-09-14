import { useQuery } from '@tanstack/react-query'

import { api } from '../lib/api/client'
import type { Data } from '../lib/api/types'

/**
 * Sidebar badges (docs/phase-5-admin-core.md §3.1). Never a constant: each is the total of the list it opens, under the
 * filter the API returns with it. Invalidated after every mutation (App.tsx), polled every minute and on focus, because
 * website bookings, enquiries and time itself change counts without anyone clicking.
 */
export type NavCounts = Record<string, { count: number; filter: Record<string, string> }>

export const NAV_COUNTS_KEY = ['nav-counts'] as const

export function useNavCounts(enabled: boolean) {
  return useQuery({
    queryKey: NAV_COUNTS_KEY,
    queryFn: ({ signal }) => api.get<Data<NavCounts>>('admin/nav-counts', signal).then((response) => response.data),
    enabled,
    staleTime: 0,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  })
}

/** The list URL a badge opens: its path with exactly the badge's filter. */
export function badgeHref(path: string, filter: Record<string, string>): string {
  const query = new URLSearchParams(filter).toString()
  return query ? `${path}?${query}` : path
}
