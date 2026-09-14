import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import { SupportView } from '@/features/portal/SupportView';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/support'>) {
  return portalMetadata((await params).locale);
}

export default async function PortalSupport({ params }: PageProps<'/[locale]/portal/support'>) {
  const { locale } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="support" pathname="/support">
      <SupportView />
    </PortalPage>
  );
}
