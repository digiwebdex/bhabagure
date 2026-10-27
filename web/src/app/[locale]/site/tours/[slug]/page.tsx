import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { setRequestLocale } from 'next-intl/server';

import { SiteChrome } from '@/features/SiteChrome';
import { TourLandingPage } from '@/features/tour-pages/TourLandingPage';
import { routing, type AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { loadContent } from '@/lib/content/source';
import { localizedPath, tourPagePath } from '@/lib/links';
import { jsonLd, touristTrip } from '@/lib/structured-data';

export async function generateStaticParams() {
  const { tourPages } = await loadContent();
  return routing.locales.flatMap((locale) => (tourPages ?? []).map((page) => ({ locale, slug: page.slug })));
}

export async function generateMetadata({ params }: PageProps<'/[locale]/site/tours/[slug]'>): Promise<Metadata> {
  const { locale, slug } = await params;
  const views = await getSiteViews(locale as AppLocale);
  const page = views.tourPages.find((p) => p.slug === slug);
  if (!page) return {};
  const path = tourPagePath(slug);
  const description = page.intro?.slice(0, 200) || page.pkg.summary;
  return {
    title: `${page.title} · ${views.settings.brand}`,
    description,
    metadataBase: new URL(siteUrl()),
    alternates: { canonical: localizedPath(locale as AppLocale, path), languages: { bn: path, en: localizedPath('en', path) } },
    openGraph: { title: page.title, description, images: page.hero ? [page.hero.image.url] : [], type: 'website' },
  };
}

/** A group tour's own landing page (docs/tour-landing-pages.md): shared on WhatsApp and Facebook, read without signing in. */
export default async function TourPage({ params }: PageProps<'/[locale]/site/tours/[slug]'>) {
  const { locale: param, slug } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);
  const page = views.tourPages.find((p) => p.slug === slug);
  if (!page) notFound();
  const path = tourPagePath(slug);

  return (
    <SiteChrome locale={locale} views={views} pathname={path}>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(touristTrip(page.pkg, siteUrl() + localizedPath(locale, path), views.settings)) }} />
      <TourLandingPage page={page} views={views} locale={locale} />
    </SiteChrome>
  );
}
