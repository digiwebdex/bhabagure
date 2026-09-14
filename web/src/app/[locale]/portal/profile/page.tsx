import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import { ProfileView } from '@/features/portal/ProfileView';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/profile'>) {
  return portalMetadata((await params).locale);
}

export default async function PortalProfile({ params }: PageProps<'/[locale]/portal/profile'>) {
  const { locale } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="profile" pathname="/profile">
      <ProfileView />
    </PortalPage>
  );
}
