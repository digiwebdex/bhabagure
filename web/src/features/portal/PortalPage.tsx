import 'server-only';

import type { Metadata } from 'next';
import { getTranslations, setRequestLocale } from 'next-intl/server';
import type { ReactNode } from 'react';

import { LanguageToggle } from '@/components/LanguageToggle';
import type { AppLocale } from '@/i18n/routing';
import { getSiteViews } from '@/lib/content';

import { PortalShell, type PortalTab } from './PortalShell';

/** Every portal page: private, never indexed. */
export async function portalMetadata(locale: string): Promise<Metadata> {
  const t = await getTranslations({ locale, namespace: 'meta' });
  return { title: t('portalTitle'), robots: { index: false, follow: false } };
}

/** The shell around one portal page, with the office contact from the CMS settings. */
export async function PortalPage({ locale, tab, pathname, children }: { locale: AppLocale; tab: PortalTab; pathname: string; children: ReactNode }) {
  setRequestLocale(locale);
  const { settings } = await getSiteViews(locale);

  return (
    <PortalShell
      tab={tab}
      contact={{ phone: settings.contact.phone, whatsapp: settings.contact.whatsapp, opens: settings.hours.opens, closes: settings.hours.closes }}
      languageToggle={<LanguageToggle locale={locale} pathname={pathname} />}
    >
      {children}
    </PortalShell>
  );
}
