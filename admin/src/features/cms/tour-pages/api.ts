import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { api } from '../../../lib/api/client'
import type { Data, Media } from '../../../lib/api/types'

/** Website → Tour landing pages (docs/tour-landing-pages.md). */
export interface TourPageSummary {
  id: number
  slug: string
  tour_package_id: number
  promote_on_home: boolean
  sort_order: number
  status: 'draft' | 'published'
  hero: Media | null
  package_title: string | null
  package_slug: string | null
  package_status: string | null
  updated_at: string | null
}

/** What the page reads from its package: shown in the editor, edited in Catalogue → Packages. */
export interface TourPagePackage {
  id: number
  slug: string
  title_en: string
  title_bn: string | null
  overview_en: string | null
  overview_bn: string | null
  status: string
  cover: Media | null
  days: { day_number: number; title_en: string | null; title_bn: string | null }[]
  departures: { id: number; departs_on: string; returns_on: string | null; status: string }[]
}

export interface TourPage extends TourPageSummary {
  title_bn: string | null
  title_en: string | null
  intro_bn: string | null
  intro_en: string | null
  hero_media_id: number | null
  countdown_departure_id: number | null
  day_photos: { day_number: number; media_id: number; image: Media | null }[]
  package: TourPagePackage | null
}

export interface TourPageOption {
  id: number
  title_en: string
  slug: string
  status: string
  landing_page_id: number | null
}

/** The editor's form: images kept whole for their thumbnails, sent as ids. */
export interface TourPageForm {
  slug: string
  tour_package_id: number
  title_bn: string
  title_en: string
  intro_bn: string
  intro_en: string
  hero: Media | null
  countdown_departure_id: number | null
  promote_on_home: boolean
  day_photos: { day_number: number; media: Media }[]
}

export const emptyTourPage = (): TourPageForm => ({
  slug: '',
  tour_package_id: 0,
  title_bn: '',
  title_en: '',
  intro_bn: '',
  intro_en: '',
  hero: null,
  countdown_departure_id: null,
  promote_on_home: false,
  day_photos: [],
})

export const toTourPageForm = (page: TourPage): TourPageForm => ({
  slug: page.slug,
  tour_package_id: page.tour_package_id,
  title_bn: page.title_bn ?? '',
  title_en: page.title_en ?? '',
  intro_bn: page.intro_bn ?? '',
  intro_en: page.intro_en ?? '',
  hero: page.hero,
  countdown_departure_id: page.countdown_departure_id,
  promote_on_home: page.promote_on_home,
  day_photos: page.day_photos.filter((p) => p.image).map((p) => ({ day_number: p.day_number, media: p.image! })),
})

const payload = (form: TourPageForm) => ({
  slug: form.slug,
  tour_package_id: form.tour_package_id,
  title_bn: form.title_bn,
  title_en: form.title_en,
  intro_bn: form.intro_bn,
  intro_en: form.intro_en,
  hero_media_id: form.hero?.id ?? null,
  countdown_departure_id: form.countdown_departure_id,
  promote_on_home: form.promote_on_home,
  day_photos: form.day_photos.map((p) => ({ day_number: p.day_number, media_id: p.media.id })),
})

export const useTourPages = () =>
  useQuery({ queryKey: ['tour-pages'], queryFn: ({ signal }) => api.get<Data<TourPageSummary[]>>('admin/tour-landing-pages', signal) })

export const useTourPage = (id: number | null) =>
  useQuery({ queryKey: ['tour-page', id], queryFn: ({ signal }) => api.get<Data<TourPage>>(`admin/tour-landing-pages/${id}`, signal), enabled: id !== null })

export const useTourPageOptions = () =>
  useQuery({ queryKey: ['tour-page-options'], queryFn: ({ signal }) => api.get<Data<TourPageOption[]>>('admin/tour-landing-pages/options', signal) })

/** After any change the list, the editor and the package choices may all be stale. */
export function useTourPageMutation<TVariables, TResult>(mutationFn: (variables: TVariables) => Promise<TResult>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: (result) => {
      const saved = (result as Partial<Data<TourPage>> | null)?.data
      if (saved && typeof saved === 'object' && 'slug' in saved) queryClient.setQueryData(['tour-page', saved.id], { data: saved })
      void queryClient.invalidateQueries({ queryKey: ['tour-pages'] })
      void queryClient.invalidateQueries({ queryKey: ['tour-page'] })
      void queryClient.invalidateQueries({ queryKey: ['tour-page-options'] })
      void queryClient.invalidateQueries({ queryKey: ['package'] })
    },
  })
}

export const tourPageActions = {
  create: (form: TourPageForm) => api.post<Data<TourPage>>('admin/tour-landing-pages', payload(form)),
  update: (id: number, form: TourPageForm) => api.put<Data<TourPage>>(`admin/tour-landing-pages/${id}`, payload(form)),
  transition: (id: number, action: 'publish' | 'unpublish') => api.post<Data<TourPage>>(`admin/tour-landing-pages/${id}/${action}`),
  remove: (id: number) => api.delete(`admin/tour-landing-pages/${id}`),
  // A 30-minute link to the page as saved, draft or not (docs/tour-landing-pages.md §6).
  previewLink: (id: number) => api.post<Data<{ url_bn: string; url_en: string; expires_at: string }>>(`admin/tour-landing-pages/${id}/preview-link`),
}
