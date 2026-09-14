import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { loadContent } from '@/lib/content/source';
import { PackageDetailActions, PackageDetailBody, PackageDetailHeading } from '@/features/packages/PackageDetail';
import { SiteChrome } from '@/features/SiteChrome';
import { Link } from '@/i18n/navigation';
import { routing, type AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { localizedPath, packagePath } from '@/lib/links';
import { jsonLd, touristTrip } from '@/lib/structured-data';

export async function generateStaticParams() {
  const { packages } = await loadContent();
  return routing.locales.flatMap((locale) => packages.filter((p) => p.status === 'published').map((p) => ({ locale, slug: p.slug })));
}

export async function generateMetadata({ params }: PageProps<'/[locale]/site/packages/[slug]'>): Promise<Metadata> {
  const { locale, slug } = await params;
  const views = await getSiteViews(locale as AppLocale);
  const pkg = views.packages.find((p) => p.slug === slug);
  if (!pkg) return {};
  const t = await getTranslations({ locale, namespace: 'common' });
  const path = packagePath(slug);
  return {
    title: `${pkg.title} · ${t('brand')}`,
    description: pkg.summary,
    metadataBase: new URL(siteUrl()),
    alternates: { canonical: localizedPath(locale as AppLocale, path), languages: { bn: path, en: localizedPath('en', path) } },
    openGraph: { title: pkg.title, description: pkg.summary, images: pkg.images.slice(0, 1).map((img) => img.url), type: 'website' },
  };
}

/** A package on its own URL: direct visits, shared WhatsApp links and search engines. */
export default async function PackagePage({ params }: PageProps<'/[locale]/site/packages/[slug]'>) {
  const { locale: param, slug } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);
  const pkg = views.packages.find((p) => p.slug === slug);
  if (!pkg) notFound();
  const t = await getTranslations({ locale, namespace: 'common' });
  const path = packagePath(slug);

  return (
    <SiteChrome locale={locale} views={views} pathname={path}>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(touristTrip(pkg, siteUrl() + localizedPath(locale, path), views.settings)) }} />
      <article className="mx-auto flex max-w-modal-md flex-col gap-6 px-section-x py-section-y">
        <Link href="/#packages" className="text-14 font-semibold">
          {t('backToHome')}
        </Link>
        <PackageDetailHeading pkg={pkg} as="h1" />
        <PackageDetailBody pkg={pkg} />
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-16 border border-hairline bg-paper-soft px-fluid-18-28 py-4">
          <PackageDetailActions pkg={pkg} />
        </div>
      </article>
    </SiteChrome>
  );
}
