import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import { TicketView } from '@/features/portal/SupportView';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/support/[number]'>) {
  return portalMetadata((await params).locale);
}

export default async function PortalTicket({ params }: PageProps<'/[locale]/portal/support/[number]'>) {
  const { locale, number } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="support" pathname={`/support/${number}`}>
      <TicketView number={decodeURIComponent(number)} />
    </PortalPage>
  );
}
