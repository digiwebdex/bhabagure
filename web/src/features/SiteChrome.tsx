import { getTranslations } from 'next-intl/server';
import type { ReactNode } from 'react';

import { RevealObserver } from '@/components/motion/RevealObserver';
import { ScrollProgress } from '@/components/motion/ScrollProgress';
import { SiteContentProvider } from '@/components/providers/SiteContentProvider';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { emptySections, localizedPath } from '@/lib/links';

import { ChatAssistant } from './assistant/ChatAssistant';
import { FloatingActions } from './assistant/FloatingActions';
import { AuthModal } from './auth/AuthModal';
import { BookingModal } from './booking/BookingModal';
import { SiteFooter } from './footer/SiteFooter';
import { SiteHeader } from './header/SiteHeader';
import { PackageModalHost } from './packages/PackageModalHost';
import { CookieConsent } from './CookieConsent';
import { VisitorAnalytics } from './VisitorAnalytics';

interface SiteChromeProps {
  locale: AppLocale;
  views: SiteViews;
  /** The page's path without locale prefix — drives the language toggle and in-page links. */
  pathname: string;
  /** Home-page sections this page also has (e.g. "contact"): the header links to them on the page instead of home. */
  pageSections?: string[];
  /** False on a staff preview (docs/tour-landing-pages.md §6): no visit recorded, no cookie banner, no GA4. */
  analytics?: boolean;
  children: ReactNode;
}

/** Header, footer, floating buttons, assistant and modals shared by every website page. */
export async function SiteChrome({ locale, views, pathname, pageSections, analytics = true, children }: SiteChromeProps) {
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
        <SiteFooter locale={locale} settings={views.settings} emptySections={emptySections(views)} />
        <FloatingActions />
        <ChatAssistant />
        <PackageModalHost homePath={localizedPath(locale, pathname)} />
        <BookingModal />
        <AuthModal />
        <RevealObserver />
        {/* Visitor analytics (docs/visitor-analytics.md): website pages only — never the portal or the admin. The cookie
            banner first, so a visitor who accepted before has GA4 ready for the page view. */}
        {analytics ? (
          <>
            <CookieConsent measurementId={views.settings.ga4 ?? null} />
            <VisitorAnalytics />
          </>
        ) : null}
      </div>
    </SiteContentProvider>
  );
}
