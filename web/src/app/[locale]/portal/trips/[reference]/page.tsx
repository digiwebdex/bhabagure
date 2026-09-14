import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import { TripDetailView } from '@/features/portal/TripDetailView';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/trips/[reference]'>) {
  return portalMetadata((await params).locale);
}

/** One trip, and where SSLCommerz returns a payment started in the portal. */
export default async function PortalTrip({ params }: PageProps<'/[locale]/portal/trips/[reference]'>) {
  const { locale, reference } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="trips" pathname={`/trips/${reference}`}>
      <TripDetailView reference={decodeURIComponent(reference)} />
    </PortalPage>
  );
}
