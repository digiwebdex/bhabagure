import type { Metadata } from 'next';
import { setRequestLocale } from 'next-intl/server';

import { LegalPage, legalMetadata } from '@/features/legal/LegalPage';
import { SiteChrome } from '@/features/SiteChrome';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';

export async function generateMetadata({ params }: PageProps<'/[locale]/site/refund-policy'>): Promise<Metadata> {
  return legalMetadata('refund-policy', (await params).locale as AppLocale);
}

export default async function Page({ params }: PageProps<'/[locale]/site/refund-policy'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);

  return (
    <SiteChrome locale={locale} views={views} pathname="/refund-policy">
      <LegalPage slug="refund-policy" locale={locale} />
    </SiteChrome>
  );
}
