'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';
import { clearGa4Cookies, cookieChoice, COOKIE_SETTINGS_EVENT, ga4PageView, loadGa4, optedOutByBrowser, saveCookieChoice, setupGa4 } from '@/lib/ga4';

/**
 * The cookie banner for Google Analytics 4 (docs/visitor-analytics.md §GA4), only when the super admin has connected
 * GA4. Google's tag loads only after Accept; Decline, or a browser asking not to be tracked, and it never loads.
 * "Cookie settings" in the footer brings it back. Our own visitor analytics is cookieless and runs either way.
 */
export function CookieConsent({ measurementId }: { measurementId: string | null }) {
  const t = useTranslations('cookies');
  const [open, setOpen] = useState(false);

  useEffect(() => {
    setupGa4(measurementId);
    const ask = () => setOpen(Boolean(measurementId) && !optedOutByBrowser());
    // First visit: ask. Answered before: stay quiet until "Cookie settings".
    if (measurementId && cookieChoice() === null && !optedOutByBrowser()) queueMicrotask(ask);
    window.addEventListener(COOKIE_SETTINGS_EVENT, ask);
    return () => window.removeEventListener(COOKIE_SETTINGS_EVENT, ask);
  }, [measurementId]);

  if (!open) return null;

  const accept = () => {
    saveCookieChoice('granted');
    loadGa4();
    ga4PageView(window.location.pathname);
    setOpen(false);
  };
  const decline = () => {
    const before = cookieChoice();
    saveCookieChoice('denied');
    setOpen(false);
    // Accepted before: Google's tag is running and has set cookies — remove them and reload without it.
    if (before === 'granted') {
      clearGa4Cookies();
      window.location.reload();
    }
  };

  return (
    <section
      role="dialog"
      aria-labelledby="cookie-title"
      aria-describedby="cookie-text"
      className="fixed inset-x-3 bottom-3 z-70 mx-auto flex max-w-xl flex-col gap-3 rounded-16 border border-hairline bg-white p-4 text-ink shadow-xl sm:p-5"
      data-testid="cookie-consent"
    >
      <h2 id="cookie-title" className="text-16 font-semibold">
        {t('title')}
      </h2>
      <p id="cookie-text" className="text-14 leading-1.55 text-muted">
        {t('text')}{' '}
        <Link href="/privacy" className="font-semibold text-blue underline">
          {t('privacy')}
        </Link>
      </p>
      <div className="flex flex-wrap justify-end gap-2.5">
        <button type="button" onClick={decline} className={buttonClass('outlineInk', 'none', 'px-5 py-2.5 text-14')}>
          {t('decline')}
        </button>
        <button type="button" onClick={accept} className={buttonClass('cta', 'none', 'px-5.5 py-2.5 text-14')}>
          {t('accept')}
        </button>
      </div>
    </section>
  );
}

/** "Cookie settings" in the footer, when GA4 is connected: opens the banner again to change the answer. */
export function CookieSettingsButton() {
  const t = useTranslations('cookies');
  return (
    <button type="button" onClick={() => window.dispatchEvent(new Event(COOKIE_SETTINGS_EVENT))} className="cursor-pointer text-white underline hover:text-orange-light">
      {t('settings')}
    </button>
  );
}
