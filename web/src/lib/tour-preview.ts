import 'server-only';

import type { AppLocale } from '@/i18n/routing';

import { loadContent } from './content/source';
import type { ContentBundle, Departure, TourPackage, TourPage } from './content/types';
import { buildViews, type SiteViews, type TourPageView } from './content/views';

/**
 * A landing page as saved, draft or not, for staff (docs/tour-landing-pages.md §6). The token comes from the preview
 * cookie or, for the PDF renderer, a request header — never from the address — and goes to the API in a header. Nothing
 * here is cached: an expired or invalid token gets nothing.
 */
export async function loadTourPreview(token: string): Promise<{ page: TourPage; package: TourPackage; departures: Departure[] } | null> {
  const base = process.env.API_INTERNAL_URL || process.env.API_URL;
  if (!base || !/^[A-Za-z0-9_-]{8,120}\.[A-Za-z0-9_-]{8,120}$/.test(token)) return null;
  const res = await fetch(`${base}/api/v1/public/tour-pages/preview`, { headers: { 'X-Preview-Token': token, Accept: 'application/json' }, cache: 'no-store' });
  if (!res.ok) return null;
  return (await res.json()).data;
}

/** The website's views with the previewed page and its package in them, as if published — for this render only. */
export async function previewViews(preview: NonNullable<Awaited<ReturnType<typeof loadTourPreview>>>, locale: AppLocale): Promise<{ views: SiteViews; page: TourPageView | null }> {
  const bundle = await loadContent();
  const code = preview.package.code;
  const withPreview: ContentBundle = {
    ...bundle,
    packages: [...bundle.packages.filter((p) => p.slug !== preview.package.slug), { ...preview.package, status: 'published' }],
    departures: [...bundle.departures.filter((d) => d.packageCode !== code), ...preview.departures],
    tourPages: [preview.page],
  };
  const views = buildViews(withPreview, locale);
  return { views, page: views.tourPages[0] ?? null };
}
