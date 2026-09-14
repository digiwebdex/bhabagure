import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../../lib/api/client'
import type { Media, Paginated } from '../../../lib/api/types'

/** Same limits as the API (config/bhabaghure.php → media), checked here first so a 12 MB photo fails instantly. */
export const MAX_UPLOAD_BYTES = 5 * 1024 * 1024
export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']

export function useMediaList(search: string, page: number) {
  return useQuery({
    queryKey: ['media', search, page],
    queryFn: ({ signal }) => api.get<Paginated<Media>>(`admin/media?page=${page}&search=${encodeURIComponent(search)}`, signal),
    placeholderData: keepPreviousData,
  })
}

export function useDeleteMedia() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api.delete(`admin/media/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['media'] }),
  })
}
