import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import { TripsView } from '@/features/portal/TripsView';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal'>) {
  return portalMetadata((await params).locale);
}

/** customer.bhabaghure.com.bd — My trips (docs/phase-6-customer-portal.md §3.2). */
export default async function PortalHome({ params }: PageProps<'/[locale]/portal'>) {
  const { locale } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="trips" pathname="/">
      <TripsView />
    </PortalPage>
  );
}
