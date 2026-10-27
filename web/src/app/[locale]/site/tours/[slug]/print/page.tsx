import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { setRequestLocale } from 'next-intl/server';

import { TourPrint } from '@/features/tour-pages/TourPrint';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';
import { printDate } from '@/lib/tour-booking';

export const dynamic = 'force-dynamic';
// No tab icon: the PDF renderer would have to fetch it, and the brochure doesn't need it.
export const metadata: Metadata = { robots: { index: false, follow: false }, icons: null };

/** A published landing page laid out for its brochure PDF (docs/tour-landing-pages.md §7); only the PDF renderer reads it. */
export default async function TourPrintPage({ params, searchParams }: PageProps<'/[locale]/site/tours/[slug]/print'>) {
  const { locale: param, slug } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);
  const page = views.tourPages.find((p) => p.slug === slug);
  if (!page) notFound();

  return <TourPrint page={page} views={views} locale={locale} asOf={printDate((await searchParams).asOf)} />;
}
