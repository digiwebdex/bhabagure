import { gridCategories } from '@bhabaghure/pricing'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../../lib/api/client'
import type { Data, Departure, Destination, PackageImage, PackageStatus, PackageSummary, Tag, TourPackage } from '../../../lib/api/types'

export type PackageForm = Omit<TourPackage, 'id' | 'status' | 'published_at' | 'sort_order' | 'missing_bangla' | 'cover' | 'updated_at' | 'destination' | 'images' | 'wp_trip_id'>

export const emptyPackage = (): PackageForm => ({
  code: '',
  slug: '',
  destination_id: 0,
  title_bn: '',
  title_en: '',
  summary_bn: '',
  summary_en: '',
  duration_days: 1,
  duration_nights: 0,
  regular_price: 0,
  sale_price: null,
  price_grid: null,
  includes_airfare: null,
  group_mode: 'group',
  min_pax: null,
  departure_mode: 'any_date',
  difficulty: null,
  is_featured: false,
  seo_title_bn: '',
  seo_title_en: '',
  seo_description_bn: '',
  seo_description_en: '',
  itinerary: [],
  includes: [],
  excludes: [],
  activities: [],
  trip_types: [],
})

export function toForm(pkg: TourPackage): PackageForm {
  const keys = Object.keys(emptyPackage()) as (keyof PackageForm)[]
  return Object.fromEntries(keys.map((key) => [key, pkg[key]])) as PackageForm
}

export const usePackages = (status: PackageStatus | 'all', destination: string, search: string) =>
  useQuery({
    queryKey: ['packages', status, destination, search],
    queryFn: ({ signal }) =>
      api.get<Data<PackageSummary[]>>(`admin/packages?${new URLSearchParams({ ...(status === 'all' ? {} : { status }), ...(destination ? { destination } : {}), ...(search ? { search } : {}) })}`, signal),
  })

export const usePackage = (id: number | null) =>
  useQuery({
    queryKey: ['package', id],
    queryFn: ({ signal }) => api.get<Data<TourPackage>>(`admin/packages/${id}`, signal),
    enabled: id !== null,
  })

export const useDestinations = () =>
  useQuery({ queryKey: ['destinations'], queryFn: ({ signal }) => api.get<Data<Destination[]>>('admin/destinations', signal), staleTime: 5 * 60_000 })

export const useTags = () => useQuery({ queryKey: ['tags'], queryFn: ({ signal }) => api.get<Data<Tag[]>>('admin/tags', signal), staleTime: 5 * 60_000 })

export const useDepartures = (packageId: number) =>
  useQuery({ queryKey: ['departures', packageId], queryFn: ({ signal }) => api.get<Data<Departure[]>>(`admin/packages/${packageId}/departures`, signal) })

/** After any package change the list, the editor and the tag list may all be stale. */
export function usePackageMutation<TVariables, TResult>(mutationFn: (variables: TVariables) => Promise<TResult>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: (result) => {
      // The answer is the package as saved: show it at once, so a publish flips the status and its button now rather
      // than after the refetch (until then the old button stayed clickable and sent the old action again).
      const saved = (result as Partial<Data<TourPackage>> | null)?.data
      if (saved && typeof saved === 'object' && 'slug' in saved) queryClient.setQueryData(['package', saved.id], { data: saved })
      void queryClient.invalidateQueries({ queryKey: ['packages'] })
      void queryClient.invalidateQueries({ queryKey: ['package'] })
      void queryClient.invalidateQueries({ queryKey: ['tags'] })
    },
  })
}

export const packageActions = {
  create: (form: PackageForm) => api.post<Data<TourPackage>>('admin/packages', form),
  update: (id: number, form: PackageForm) => api.put<Data<TourPackage>>(`admin/packages/${id}`, form),
  transition: (id: number, action: 'publish' | 'unpublish' | 'archive') => api.post<Data<TourPackage>>(`admin/packages/${id}/${action}`),
  remove: (id: number) => api.delete(`admin/packages/${id}`),
  reorder: (ids: number[]) => api.put('admin/packages/order', { ids }),
  addImage: (id: number, mediaId: number) => api.post<Data<PackageImage[]>>(`admin/packages/${id}/images`, { media_id: mediaId }),
  reorderImages: (id: number, imageIds: number[], coverId: number) => api.put<Data<PackageImage[]>>(`admin/packages/${id}/images/order`, { image_ids: imageIds, cover_image_id: coverId }),
  removeImage: (id: number, imageId: number) => api.delete<Data<PackageImage[]>>(`admin/packages/${id}/images/${imageId}`),
}

/**
 * The publish checklist, mirrored from PackageController::publishProblems so the editor can show progress
 * before saving. The API decides.
 */
export function publishChecklist(form: PackageForm, imageCount: number) {
  return [
    { key: 'titleBn', done: !!form.title_bn?.trim() },
    // A hotel-category grid prices the package instead of the one price (Phase 8 §4.D).
    { key: 'price', done: form.regular_price > 0 || gridCategories(form.price_grid).length > 0 },
    { key: 'duration', done: form.duration_days >= 1 },
    { key: 'itinerary', done: form.itinerary.length > 0 },
    { key: 'includes', done: form.includes.length > 0 },
    { key: 'photos', done: imageCount > 0 },
  ] as const
}

/** "Nepal Mustang Adventure" → "nepal-mustang-adventure". Non-Latin text is dropped (slugs stay ASCII). */
export function slugify(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 190)
}
