'use client';

import Image from 'next/image';
import { useLocale, useTranslations } from 'next-intl';
import { useEffect, useState, type ReactNode } from 'react';

import { Link } from '@/i18n/navigation';
import { restoreSession, signOut } from '@/lib/customer-api';
import { initialsOf } from '@/lib/initials';
import { displayPhone, telUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';
import { useCustomerSession } from '@/state/customer-session';

import { CodeSignIn } from '../auth/CodeSignIn';

export type PortalTab = 'trips' | 'docs' | 'payments' | 'support' | 'profile';

const TABS: { key: PortalTab; href: string }[] = [
  { key: 'trips', href: '/' },
  { key: 'docs', href: '/documents' },
  { key: 'payments', href: '/payments' },
  { key: 'support', href: '/support' },
  { key: 'profile', href: '/profile' },
];

export type PortalContact = { phone: string; whatsapp: string; opens: number; closes: number };

interface PortalShellProps {
  tab: PortalTab;
  contact: PortalContact;
  /** The server-rendered language toggle for this page. */
  languageToggle: ReactNode;
  children: ReactNode;
}

/**
 * customer.bhabaghure.com.bd (Bhabaghure Customer Portal.dc.html): navy header with the five tabs, content, navy footer.
 * The session is restored from the refresh cookie on load; without one the page is the sign-in screen. Private pages
 * aren't cached for offline use (docs/phase-6-customer-portal.md §2 #10): offline, the portal says so.
 */
export function PortalShell({ tab, contact, languageToggle, children }: PortalShellProps) {
  const t = useTranslations('portal');
  const tc = useTranslations('common');
  const locale = useLocale();
  const f = useFormatters();
  const status = useCustomerSession((state) => state.status);
  const customer = useCustomerSession((state) => state.customer);
  const [offline, setOffline] = useState(false);

  useEffect(() => {
    if (useCustomerSession.getState().status === 'unknown') void restoreSession(locale);
  }, [locale]);

  useEffect(() => {
    const update = () => setOffline(!navigator.onLine);
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    update();
    return () => {
      window.removeEventListener('online', update);
      window.removeEventListener('offline', update);
    };
  }, []);

  const phone = f.digits(displayPhone(contact.phone));

  return (
    <div className="flex min-h-dvh flex-col bg-app-bg text-app-text">
      {offline ? (
        <div role="status" className="sticky top-0 z-40 bg-amber px-4 py-2.25 text-center text-13.5 font-semibold text-white">
          {t('offline', { phone })}
        </div>
      ) : null}

      <header className="bg-ink-deep text-white">
        <div className="mx-auto flex max-w-portal flex-wrap items-center justify-between gap-x-fluid-12-24 gap-y-3 px-fluid-16-22 py-3.5">
          <div className="flex min-w-0 items-center gap-3">
            <Image src="/brand/logo-wordmark-light.png" alt={tc('brand')} width={852} height={378} priority className="block h-logo-footer w-auto shrink-0" />
            <span className="font-display text-10 font-bold tracking-[0.16em] whitespace-nowrap text-orange uppercase">{tc('myAccount')}</span>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            {languageToggle}
            {status === 'signedIn' && customer ? (
              <>
                <span className="flex items-center gap-2.25 text-14">
                  <span aria-hidden className="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-135/srgb from-blue to-orange font-display text-12 font-extrabold">
                    {initialsOf(customer.name)}
                  </span>
                  {customer.name}
                </span>
                <button
                  type="button"
                  onClick={() => void signOut(locale)}
                  className="cursor-pointer rounded-pill border border-white/25 bg-transparent px-3.5 py-1.75 text-13 font-semibold whitespace-nowrap text-white hover:border-orange hover:text-orange"
                >
                  {t('signOut')}
                </button>
              </>
            ) : null}
          </div>
        </div>
        {status === 'signedIn' ? (
          <nav aria-label={t('tabsLabel')} className="mx-auto flex max-w-portal gap-1.5 overflow-x-auto px-fluid-16-22">
            {TABS.map((item) => (
              <Link
                key={item.key}
                href={item.href}
                aria-current={item.key === tab ? 'page' : undefined}
                className={`border-b-3 px-3.5 py-3 text-14 font-semibold whitespace-nowrap ${
                  item.key === tab ? 'border-orange text-white hover:text-white' : 'border-transparent text-on-navy hover:text-white'
                }`}
              >
                {t(`tabs.${item.key}`)}
              </Link>
            ))}
          </nav>
        ) : null}
      </header>

      <main className="mx-auto flex w-full max-w-portal flex-1 flex-col gap-4.5 px-fluid-16-22 py-fluid-18-28">
        {status === 'unknown' ? (
          <p role="status" className="rounded-18 border border-portal-line bg-app-surface p-4.5 text-14 text-app-muted">
            {t('checkingSession')}
          </p>
        ) : status === 'signedOut' ? (
          <section className="mx-auto flex w-full max-w-110 flex-col gap-4 rounded-20 border border-portal-line bg-app-surface p-fluid-18-26">
            <div className="flex flex-col gap-1">
              <h1 className="text-fluid-19-23 font-bold tracking-title">{t('signInTitle')}</h1>
              <p className="text-13.5 leading-1.55 text-app-muted">{t('signInSubtitle')}</p>
            </div>
            <CodeSignIn onSignedIn={() => undefined} whatsapp={contact.whatsapp} />
          </section>
        ) : (
          children
        )}
      </main>

      <footer className="bg-ink-deep px-fluid-16-22 py-4.5 text-on-navy">
        <div className="mx-auto flex max-w-portal flex-wrap justify-between gap-2.5 text-13">
          <span>
            {t('footerSupport')}{' '}
            <a href={telUrl(contact.phone)} className="text-orange-light hover:text-white">
              {phone}
            </a>
          </span>
          <span>{t('footerHours', { open: f.number(contact.opens), close: f.number(contact.closes - 12) })}</span>
        </div>
      </footer>
    </div>
  );
}
