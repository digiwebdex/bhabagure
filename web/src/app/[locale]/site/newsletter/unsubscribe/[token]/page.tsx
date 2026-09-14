import type { Metadata } from 'next';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { UnsubscribePanel } from '@/features/newsletter/UnsubscribePanel';
import { SiteChrome } from '@/features/SiteChrome';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';

export async function generateMetadata({ params }: PageProps<'/[locale]/site/newsletter/unsubscribe/[token]'>): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: 'unsubscribe' });
  // Personal link: never indexed.
  return { title: t('title'), robots: { index: false, follow: false } };
}

/**
 * Landing page of the unsubscribe link in newsletter emails. The token is signed by the API, so no login is
 * needed. Unsubscribing takes a click (POST), so mail scanners that open links don't unsubscribe anyone.
 */
export default async function UnsubscribePage({ params }: PageProps<'/[locale]/site/newsletter/unsubscribe/[token]'>) {
  const { locale: param, token } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);

  return (
    <SiteChrome locale={locale} views={views} pathname={`/newsletter/unsubscribe/${token}`}>
      <section className="mx-auto flex max-w-narrow flex-col gap-4 px-section-x py-section-y">
        <UnsubscribePanel token={token} />
      </section>
    </SiteChrome>
  );
}
