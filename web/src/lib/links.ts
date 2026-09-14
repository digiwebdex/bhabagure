import type { AppLocale } from '@/i18n/routing';

/** Public path for a locale: Bangla unprefixed, English under /en (matches src/proxy.ts). */
export function localizedPath(locale: AppLocale, path: string): string {
  const clean = path.startsWith('/') ? path : `/${path}`;
  if (locale === 'bn') return clean;
  return clean === '/' ? '/en' : `/en${clean}`;
}

export const packagePath = (slug: string) => `/packages/${slug}`;
export const postPath = (slug: string) => `/blog/${slug}`;

/** The slug in /packages/<slug> or /en/packages/<slug>, else null. */
export function packageSlugFromPathname(pathname: string): string | null {
  const match = /^(?:\/en)?\/packages\/([^/?#]+)\/?$/.exec(pathname);
  return match ? decodeURIComponent(match[1]) : null;
}

/** wa.me link. `phone` in any format; `text` is optional and URL-encoded here. */
export function whatsappUrl(phone: string, text?: string): string {
  const digits = phone.replace(/[^0-9]/g, '');
  return `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
}

export function telUrl(phone: string): string {
  return `tel:+${phone.replace(/[^0-9]/g, '')}`;
}

/** "+8801743939300" → "+880 1743 939300", the grouping used on the design's contact block. */
export function displayPhone(phone: string): string {
  const digits = phone.replace(/[^0-9]/g, '');
  const match = /^880(\d{4})(\d{6})$/.exec(digits);
  return match ? `+880 ${match[1]} ${match[2]}` : phone;
}
