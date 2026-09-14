import type { Metadata } from 'next';
import { setRequestLocale } from 'next-intl/server';

import { LEGAL_STATUS, legalDocument } from '@/features/legal/documents';
import { LegalPage } from '@/features/legal/LegalPage';
import { SiteChrome } from '@/features/SiteChrome';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';

export async function generateMetadata({ params }: PageProps<'/[locale]/site/privacy'>): Promise<Metadata> {
  const { locale } = await params;
  // Drafts pending the lawyer's review are kept out of search engines.
  return {
    title: legalDocument('privacy', locale as AppLocale).title,
    robots: LEGAL_STATUS === 'draft' ? { index: false, follow: true } : undefined,
  };
}

export default async function Page({ params }: PageProps<'/[locale]/site/privacy'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);

  return (
    <SiteChrome locale={locale} views={views} pathname="/privacy">
      <LegalPage slug="privacy" locale={locale} />
    </SiteChrome>
  );
}
