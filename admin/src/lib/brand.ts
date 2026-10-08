import { useQuery } from '@tanstack/react-query'

import { api } from './api/client'
import type { Data } from './api/types'

/** The logos and browser icon chosen in Site settings → Brand (docs/brand-logo.md), as the website gets them. */
export type BrandLogos = { logo: string | null; logoLight: string | null; icon: string | null }

export const BRAND_LOGOS_KEY = ['brand-logos']

/** The original light logo, for the navy sidebar and sign-in page. */
export const ORIGINAL_LIGHT_LOGO = '/brand/logo-wordmark-light.png'

/** Read from the public settings, so the sign-in page has them before anyone signs in. */
export function useBrandLogos(): BrandLogos | undefined {
  const query = useQuery({
    queryKey: BRAND_LOGOS_KEY,
    queryFn: ({ signal }) => api.get<Data<{ logos?: BrandLogos }>>('public/settings', signal),
    staleTime: 5 * 60_000,
  })
  return query.data?.data.logos
}
