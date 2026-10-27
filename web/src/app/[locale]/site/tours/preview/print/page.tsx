import type { Metadata } from 'next';
import { cookies, headers } from 'next/headers';
import { notFound } from 'next/navigation';
import { setRequestLocale } from 'next-intl/server';

import { TourPrint } from '@/features/tour-pages/TourPrint';
import type { AppLocale } from '@/i18n/routing';
import { printDate } from '@/lib/tour-booking';
import { loadTourPreview, previewViews } from '@/lib/tour-preview';
import { TOUR_PREVIEW_COOKIE } from '@/lib/tour-preview-cookie';

export const dynamic = 'force-dynamic';
// No tab icon: the PDF renderer would have to fetch it, and the brochure doesn't need it.
export const metadata: Metadata = { robots: { index: false, follow: false, nocache: true }, icons: null };

/**
 * A page as saved, draft or not, laid out for its brochure PDF (docs/tour-landing-pages.md §7): for the API's review
 * renders and staff. The preview token comes in a request header (the renderer) or the preview cookie — never in the
 * address. Without a valid one: not found.
 */
export default async function TourPreviewPrintPage({ params, searchParams }: PageProps<'/[locale]/site/tours/preview/print'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const token = (await headers()).get('x-preview-token') ?? (await cookies()).get(TOUR_PREVIEW_COOKIE)?.value;
  const preview = token ? await loadTourPreview(token) : null;
  const shown = preview ? await previewViews(preview, locale) : null;
  if (!shown?.page) notFound();

  return <TourPrint page={shown.page} views={shown.views} locale={locale} asOf={printDate((await searchParams).asOf)} />;
}
