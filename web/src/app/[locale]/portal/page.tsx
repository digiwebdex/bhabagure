import type { Metadata } from 'next';
import Image from 'next/image';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { LanguageToggle } from '@/components/LanguageToggle';
import type { AppLocale } from '@/i18n/routing';

export async function generateMetadata({ params }: PageProps<'/[locale]/portal'>): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: 'meta' });
  return { title: t('portalTitle'), robots: { index: false, follow: false } };
}

const TABS = ['trips', 'docs', 'payments', 'support', 'profile'] as const;

// Phase 1 placeholder for customer.bhabaghure.com.bd. Phase 6 builds the portal.
export default async function PortalHome({ params }: PageProps<'/[locale]/portal'>) {
  const { locale: param } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const t = await getTranslations({ locale });

  return (
    <div className="min-h-dvh bg-app-bg text-app-text">
      <header className="bg-ink-deep text-white">
        <div className="mx-auto flex max-w-portal items-center justify-between gap-4 px-section-x py-3.5">
          <div className="flex items-center gap-3">
            <Image
              src="/brand/logo-wordmark-light.png"
              alt={t('common.brand')}
              width={852}
              height={378}
              priority
              className="block h-logo-footer w-auto"
            />
            <span className="text-12 text-on-navy">{t('common.myAccount')}</span>
          </div>
          <LanguageToggle locale={locale} pathname="/" />
        </div>
        <nav className="mx-auto flex max-w-portal gap-5 overflow-x-auto px-section-x">
          {TABS.map((tab, i) => (
            <span
              key={tab}
              className={`whitespace-nowrap border-b-2 py-3 text-14 font-semibold ${
                i === 0 ? 'border-orange text-white' : 'border-transparent text-on-navy'
              }`}
            >
              {t(`portal.tabs.${tab}`)}
            </span>
          ))}
        </nav>
      </header>

      <main className="mx-auto max-w-portal px-section-x py-8">
        <p className="rounded-18 border border-portal-line bg-app-surface p-4.5 text-small text-app-muted">
          {t('portal.comingSoon')}
        </p>
      </main>
    </div>
  );
}
