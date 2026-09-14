import { PaymentsView } from '@/features/portal/PaymentsView';
import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/payments'>) {
  return portalMetadata((await params).locale);
}

export default async function PortalPayments({ params }: PageProps<'/[locale]/portal/payments'>) {
  const { locale } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="payments" pathname="/payments">
      <PaymentsView />
    </PortalPage>
  );
}
