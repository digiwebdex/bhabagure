import type { Metadata } from 'next';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { BookingStatusPanel } from '@/features/booking/BookingStatusPanel';
import { SiteChrome } from '@/features/SiteChrome';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';

export async function generateMetadata({ params }: PageProps<'/[locale]/site/booking/[reference]'>): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: 'bookingStatus' });
  // A private booking page: never indexed.
  return { title: t('title'), robots: { index: false, follow: false } };
}

/**
 * Where SSLCommerz sends the customer back, and the private booking link. The outcome is read from the API with the
 * booking's token — never from query parameters of the redirect.
 */
export default async function BookingStatusPage({ params }: PageProps<'/[locale]/site/booking/[reference]'>) {
  const { locale: param, reference } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);

  return (
    <SiteChrome locale={locale} views={views} pathname={`/booking/${reference}`}>
      <section className="mx-auto flex max-w-narrow flex-col gap-4 px-section-x py-section-y">
        <BookingStatusPanel reference={reference} />
      </section>
    </SiteChrome>
  );
}
