'use client';

import Image from 'next/image';
import { useLocale, useTranslations } from 'next-intl';
import { useEffect } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';
import { restoreSession } from '@/lib/customer-api';
import { useBooking } from '@/state/booking';
import { initialsOf } from '@/lib/initials';
import { teamPath } from '@/lib/links';
import { useCustomerSession } from '@/state/customer-session';
import { useSiteUi } from '@/state/site-ui';
import { useTripSearch } from '@/state/trip-search';

import { LanguageToggle } from './LanguageToggle';

interface SiteHeaderProps {
  /** The page's path without locale prefix, e.g. "/" or "/blog/nagarkot". */
  pathname: string;
  /** Home-page sections this page has too: their links stay on the page. */
  pageSections?: string[];
}

type NavItem = { kind: 'section'; id: string; key: string } | { kind: 'page'; href: string; key: string } | { kind: 'book'; key: string };

/** README: the section links on desktop, plus the team page; FAQ, gallery and "how booking works" join them in the ☰ sheet. */
const DESKTOP_NAV: NavItem[] = [
  { kind: 'section', id: 'services', key: 'services' },
  { kind: 'section', id: 'packages', key: 'packages' },
  { kind: 'section', id: 'departures', key: 'departures' },
  { kind: 'section', id: 'about', key: 'about' },
  { kind: 'page', href: teamPath, key: 'team' },
  { kind: 'section', id: 'blog', key: 'news' },
  { kind: 'book', key: 'book' },
];

const SHEET_NAV: NavItem[] = [
  { kind: 'section', id: 'services', key: 'services' },
  { kind: 'section', id: 'packages', key: 'packages' },
  { kind: 'section', id: 'departures', key: 'departures' },
  { kind: 'section', id: 'about', key: 'about' },
  { kind: 'page', href: teamPath, key: 'team' },
  { kind: 'section', id: 'blog', key: 'news' },
  { kind: 'book', key: 'book' },
  { kind: 'section', id: 'how', key: 'how' },
  { kind: 'section', id: 'faq', key: 'faq' },
  { kind: 'section', id: 'gallery', key: 'gallery' },
];

/**
 * Sticky header. ≥900px: one 73px row — logo, six links, sign-in, language, Contact.
 * <900px: 120px in two rows — logo above ☰, sign-in, language, Contact — with the links in a sheet.
 */
export function SiteHeader({ pathname, pageSections = [] }: SiteHeaderProps) {
  const t = useTranslations('nav');
  const common = useTranslations('common');
  const { packages, pricing } = useSiteContent();
  const menuOpen = useSiteUi((state) => state.menuOpen);
  const toggleMenu = useSiteUi((state) => state.toggleMenu);
  const closeMenu = useSiteUi((state) => state.closeMenu);
  const openAuth = useSiteUi((state) => state.openAuth);
  const customer = useCustomerSession((state) => state.customer);
  const startBooking = useBooking((state) => state.start);
  const locale = useLocale();
  const isHome = pathname === '/';

  // Only visitors who signed in on this device before cost a refresh request.
  useEffect(() => {
    if (useCustomerSession.getState().status === 'unknown') void restoreSession(locale, { onlyIfHinted: true });
  }, [locale]);

  const openBooking = () => {
    closeMenu();
    const { pax, date } = useTripSearch.getState();
    const first = packages[0];
    if (first) startBooking({ packageSlug: first.slug, pax, date, maxPax: pricing.maxTravellers });
  };

  const sectionLink = (id: string, label: string, className: string, onClick?: () => void) =>
    isHome || pageSections.includes(id) ? (
      <a key={id} href={`#${id}`} onClick={onClick} className={className}>
        {label}
      </a>
    ) : (
      <Link key={id} href={`/#${id}`} onClick={onClick} className={className}>
        {label}
      </Link>
    );

  const portalUrl = process.env.NEXT_PUBLIC_PORTAL_URL ?? '/';

  return (
    <header className="sticky top-0 z-20 border-b border-hairline-soft bg-white/92 text-blue-deep backdrop-blur-header">
      <div className="mx-auto flex h-header-row-mobile max-w-site flex-col justify-center gap-2 px-fluid-14-20 md:h-header-row md:flex-row md:items-center md:justify-between md:gap-fluid-12-24">
        {isHome ? (
          <a href="#top" title={common('homeLink')} className="block shrink-0 leading-none transition-opacity hover:opacity-82">
            <HeaderLogo alt={common('homeLink')} />
          </a>
        ) : (
          <Link href="/" title={common('homeLink')} className="block shrink-0 leading-none transition-opacity hover:opacity-82">
            <HeaderLogo alt={common('homeLink')} />
          </Link>
        )}

        <nav aria-label={t('menu')} className="flex min-w-0 items-center gap-fluid-10-26 text-fluid-13.5-15 font-medium">
          <span className="hidden items-center gap-fluid-10-26 md:flex">
            {DESKTOP_NAV.map((item) =>
              item.kind === 'book' ? (
                <button key="book" type="button" onClick={openBooking} className="cursor-pointer whitespace-nowrap text-ink-deep hover:text-orange">
                  {t(item.key)}
                </button>
              ) : item.kind === 'page' ? (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={pathname === item.href ? 'page' : undefined}
                  className="whitespace-nowrap text-ink-deep hover:text-orange aria-[current=page]:text-orange-deep"
                >
                  {t(item.key)}
                </Link>
              ) : (
                sectionLink(item.id, t(item.key), 'whitespace-nowrap text-ink-deep hover:text-orange')
              ),
            )}
          </span>

          <button
            type="button"
            onClick={toggleMenu}
            aria-expanded={menuOpen}
            aria-controls="site-menu"
            title={t('menu')}
            className="flex h-9.5 w-10.5 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-12 border border-input bg-white text-ink-deep hover:border-orange md:hidden"
          >
            <span className="block h-0.5 w-4.25 rounded-2 bg-current" />
            <span className="block h-0.5 w-4.25 rounded-2 bg-current" />
            <span className="block h-0.5 w-4.25 rounded-2 bg-current" />
          </button>

          {customer ? (
            <a
              href={portalUrl}
              className="flex shrink-0 items-center gap-2.25 rounded-pill border border-input bg-white py-1.25 pr-3.5 pl-1.25 whitespace-nowrap text-blue-deep hover:border-orange hover:text-orange"
            >
              <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-linear-135/srgb from-blue to-orange font-display text-11 font-extrabold text-white">
                {initialsOf(customer.name)}
              </span>
              <span className="text-13.5 font-semibold">{common('myAccount')}</span>
            </a>
          ) : (
            <button
              type="button"
              onClick={() => openAuth()}
              className="flex shrink-0 cursor-pointer items-center gap-1.75 rounded-pill border border-input bg-white px-3.75 py-2 text-13.5 font-semibold whitespace-nowrap text-blue-deep hover:border-orange hover:text-orange"
            >
              <span aria-hidden className="text-14">
                ◔
              </span>
              {t('signIn')}
            </button>
          )}

          <LanguageToggle pathname={pathname} />

          {sectionLink(
            'contact',
            t('contact'),
            buttonClass('cta', 'none', 'shrink-0 px-4.5 py-2.25 shadow-knob hover:-translate-y-px hover:shadow-cta'),
          )}
        </nav>
      </div>

      {menuOpen ? (
        <div id="site-menu" className="flex flex-col gap-0.5 border-t border-hairline-soft bg-white px-fluid-14-20 pt-2.5 pb-4 md:hidden">
          {SHEET_NAV.map((item) => {
            const row = 'flex items-center justify-between gap-3 border-b border-hairline-faint px-1 py-3.25 text-16 no-underline hover:text-orange';
            if (item.kind === 'book') {
              return (
                <button key="book" type="button" onClick={openBooking} className={`${row} cursor-pointer text-left font-bold text-orange-deep`}>
                  {t(`sheet.${item.key}`)}
                  <span aria-hidden className="text-15 text-chevron">
                    →
                  </span>
                </button>
              );
            }
            const label = (
              <>
                {t(`sheet.${item.key}`)}
                <span aria-hidden className="text-15 text-chevron">
                  ›
                </span>
              </>
            );
            if (item.kind === 'page') {
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={closeMenu}
                  aria-current={pathname === item.href ? 'page' : undefined}
                  className={`${row} font-medium text-ink-deep aria-[current=page]:text-orange-deep`}
                >
                  {label}
                </Link>
              );
            }
            return isHome ? (
              <a key={item.id} href={`#${item.id}`} onClick={closeMenu} className={`${row} font-medium text-ink-deep`}>
                {label}
              </a>
            ) : (
              <Link key={item.id} href={`/#${item.id}`} onClick={closeMenu} className={`${row} font-medium text-ink-deep`}>
                {label}
              </Link>
            );
          })}
        </div>
      ) : null}
    </header>
  );
}

function HeaderLogo({ alt }: { alt: string }) {
  return (
    <Image src="/brand/logo-wordmark.png" alt={alt} width={852} height={378} priority className="block h-logo-header w-auto" />
  );
}
