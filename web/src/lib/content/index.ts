import 'server-only';

import type { AppLocale } from '@/i18n/routing';

import { loadContent } from './source';
import { buildViews } from './views';

export async function getSiteViews(locale: AppLocale) {
  return buildViews(await loadContent(), locale);
}

/** Absolute site origin for metadata, sitemap and structured data. */
export function siteUrl(): string {
  return (process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000').replace(/\/$/, '');
}
