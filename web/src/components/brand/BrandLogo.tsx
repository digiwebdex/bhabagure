import Image from 'next/image';

/** The logos that ship with the site: for white backgrounds, and the light one for the navy footer and portal. */
const ORIGINAL = { dark: '/brand/logo-wordmark.png', light: '/brand/logo-wordmark-light.png' } as const;

/**
 * The company logo: the one chosen in Admin → Site settings → Brand (docs/brand-logo.md), or the original. `tone` is the
 * background it sits on: "dark" (navy) takes the light logo. A chosen logo is the API's own WebP, shown as it is.
 */
export function BrandLogo({ src, tone, alt, className, priority = false }: { src?: string | null; tone: 'light' | 'dark'; alt: string; className: string; priority?: boolean }) {
  if (src) {
    // eslint-disable-next-line @next/next/no-img-element -- the API's own WebP, already sized for a logo
    return <img src={src} alt={alt} className={className} loading={priority ? 'eager' : 'lazy'} decoding="async" />;
  }
  return <Image src={tone === 'dark' ? ORIGINAL.light : ORIGINAL.dark} alt={alt} width={852} height={378} priority={priority} className={className} />;
}
