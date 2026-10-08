import { useEffect } from 'react'

import { useBrandLogos } from '../lib/brand'

/** The admin's own tab icon follows the one chosen in Site settings → Brand; the original stays in index.html. */
export function BrandFavicon() {
  const icon = useBrandLogos()?.icon
  useEffect(() => {
    if (!icon) return
    const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (link) {
      link.removeAttribute('type')
      link.href = icon
    }
  }, [icon])
  return null
}
