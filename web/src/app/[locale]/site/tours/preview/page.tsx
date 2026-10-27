import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { PreviewStart } from '@/features/tour-pages/PreviewStart';
import { SiteChrome } from '@/features/SiteChrome';
import { TourLandingPage } from '@/features/tour-pages/TourLandingPage';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';
import { loadTourPreview, previewViews } from '@/lib/tour-preview';
import { TOUR_PREVIEW_COOKIE } from '@/lib/tour-preview-cookie';

// Read on every request, never cached or indexed (docs/tour-landing-pages.md §6).
export const dynamic = 'force-dynamic';
export const metadata: Metadata = { robots: { index: false, follow: false, nocache: true } };

/**
 * A staff preview of a landing page as saved, draft or not (docs/tour-landing-pages.md §6), marked "Preview — not
 * public": from the HttpOnly cookie the preview link set. No visit is recorded here, and it is in no list, home page
 * card or sitemap.
 */
export default async function TourPreviewPage({ params }: PageProps<'/[locale]/site/tours/preview'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const t = await getTranslations({ locale, namespace: 'tourPage' });
  const token = (await cookies()).get(TOUR_PREVIEW_COOKIE)?.value;
  const preview = token ? await loadTourPreview(token) : null;
  const shown = preview ? await previewViews(preview, locale) : null;
  const views = shown?.views ?? (await getSiteViews(locale));

  return (
    <SiteChrome locale={locale} views={views} pathname="/tours/preview" analytics={false}>
      {shown?.page ? (
        <>
          <div role="status" className="sticky top-0 z-40 bg-amber px-section-x py-2.5 text-center text-14 font-bold text-white" data-testid="tour-preview-banner">
            {t('previewBanner')}
          </div>
          <TourLandingPage page={shown.page} views={views} locale={locale} />
        </>
      ) : null}
      <PreviewStart showInvalid={!shown?.page} />
    </SiteChrome>
  );
}
