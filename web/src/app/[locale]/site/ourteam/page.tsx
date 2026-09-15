import type { Metadata } from 'next';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { SiteChrome } from '@/features/SiteChrome';
import { TeamPageContent } from '@/features/team/TeamPageContent';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { localizedPath, teamPath } from '@/lib/links';

export async function generateMetadata({ params }: PageProps<'/[locale]/site/ourteam'>): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale });
  const title = `${t('teamPage.title')} · ${t('common.brand')}`;
  return {
    title,
    description: t('teamPage.metaDescription'),
    metadataBase: new URL(siteUrl()),
    alternates: { canonical: localizedPath(locale as AppLocale, teamPath), languages: { bn: teamPath, en: localizedPath('en', teamPath) } },
    openGraph: { title, description: t('teamPage.metaDescription'), type: 'website', locale: locale === 'bn' ? 'bn_BD' : 'en_US' },
  };
}

/** Our team: every visible team member from the CMS (docs/phase-2-website.md; content from the Team screen). */
export default async function TeamPage({ params }: PageProps<'/[locale]/site/ourteam'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);

  return (
    <SiteChrome locale={locale} views={views} pathname={teamPath} pageSections={['contact']}>
      <TeamPageContent locale={locale} views={views} />
    </SiteChrome>
  );
}
