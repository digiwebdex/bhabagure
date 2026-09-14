import { DocumentsView } from '@/features/portal/DocumentsView';
import { PortalPage, portalMetadata } from '@/features/portal/PortalPage';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal/documents'>) {
  return portalMetadata((await params).locale);
}

export default async function PortalDocuments({ params }: PageProps<'/[locale]/portal/documents'>) {
  const { locale } = await params;

  return (
    <PortalPage locale={locale as AppLocale} tab="docs" pathname="/documents">
      <DocumentsView />
    </PortalPage>
  );
}
