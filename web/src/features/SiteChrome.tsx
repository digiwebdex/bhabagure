import { getTranslations } from 'next-intl/server';
import type { ReactNode } from 'react';

import { RevealObserver } from '@/components/motion/RevealObserver';
import { ScrollProgress } from '@/components/motion/ScrollProgress';
import { SiteContentProvider } from '@/components/providers/SiteContentProvider';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { localizedPath } from '@/lib/links';

import { ChatAssistant } from './assistant/ChatAssistant';
import { FloatingActions } from './assistant/FloatingActions';
import { AuthModal } from './auth/AuthModal';
import { BookingModal } from './booking/BookingModal';
import { SiteFooter } from './footer/SiteFooter';
import { SiteHeader } from './header/SiteHeader';
import { PackageModalHost } from './packages/PackageModalHost';

interface SiteChromeProps {
  locale: AppLocale;
  views: SiteViews;
  /** The page's path without locale prefix — drives the language toggle and in-page links. */
  pathname: string;
  /** Home-page sections this page also has (e.g. "contact"): the header links to them on the page instead of home. */
  pageSections?: string[];
  children: ReactNode;
}

/** Header, footer, floating buttons, assistant and modals shared by every website page. */
export async function SiteChrome({ locale, views, pathname, pageSections, children }: SiteChromeProps) {
  const t = await getTranslations({ locale, namespace: 'common' });

  return (
    <SiteContentProvider value={views}>
      <div className="flex min-h-dvh flex-col bg-paper text-ink">
        <a href="#main" className="sr-only z-80 rounded-pill bg-ink-deep px-4 py-2 text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2">
          {t('skipToContent')}
        </a>
        <ScrollProgress />
        <SiteHeader pathname={pathname} pageSections={pageSections} />
        <main id="main" className="flex-1">
          {children}
        </main>
        <SiteFooter locale={locale} settings={views.settings} />
        <FloatingActions />
        <ChatAssistant />
        <PackageModalHost homePath={localizedPath(locale, pathname)} />
        <BookingModal />
        <AuthModal />
        <RevealObserver />
      </div>
    </SiteContentProvider>
  );
}
