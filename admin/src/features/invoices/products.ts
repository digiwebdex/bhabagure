import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../lib/api/client'
import type { Data } from '../../lib/api/types'

/**
 * api/app/Http/Controllers/Api/V1/Admin/InvoiceProductController.php (docs/invoice-items.md): what Add New Item
 * offers — packages (published and drafts) at their per-person price, and the office's own products.
 */
export type CatalogueItem = {
  key: string
  kind: 'package' | 'product'
  id: number
  name: string
  description: string | null
  unit_price: number
  /** A package not on the website yet. */
  draft: boolean
  /** A product still offered; hidden ones show only on the Products screen. */
  is_active: boolean
}

export type ProductInput = { name: string; description: string | null; unit_price: number; is_active?: boolean }

const CATALOGUE = ['invoice-catalogue'] as const

/** `manage`: the Products screen, hidden products included and no packages. */
export const useCatalogue = (manage = false) =>
  useQuery({
    queryKey: [...CATALOGUE, manage],
    queryFn: ({ signal }) => api.get<Data<CatalogueItem[]>>(`admin/invoice-products${manage ? '?manage=1' : ''}`, signal),
    staleTime: 60_000,
  })

export function useSaveProduct() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...input }: ProductInput & { id?: number }) =>
      id ? api.put<Data<CatalogueItem>>(`admin/invoice-products/${id}`, input) : api.post<Data<CatalogueItem>>('admin/invoice-products', input),
    onSuccess: () => void client.invalidateQueries({ queryKey: CATALOGUE }),
  })
}
