import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import { QuotationView } from '@/features/portal/QuotationView';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/quotations/[number]'>) {
  return portalMetadata((await params).locale);
}

export default async function PortalQuotation({ params }: PageProps<'/[locale]/portal/quotations/[number]'>) {
  const { locale, number } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="trips" pathname={`/quotations/${number}`}>
      <QuotationView number={decodeURIComponent(number)} />
    </PortalPage>
  );
}
