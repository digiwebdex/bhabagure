/**
 * One Next.js app serves two hosts (_design/DEPLOYMENT.md):
 *   bhabaghure.com.bd           → public website   (internal segment: /[locale]/site)
 *   customer.bhabaghure.com.bd  → customer portal  (internal segment: /[locale]/portal)
 *
 * Bangla is the default language and never carries a URL prefix. English lives
 * under /en. `/bn/…` redirects to the unprefixed URL so each page has one address.
 * There is deliberately no Accept-Language redirect: Bangla first, English on request.
 *
 * Pure function so it can be tested without a running server — see host-routing.test.ts.
 */

export type Section = 'site' | 'portal';

export type RouteDecision =
  | { kind: 'rewrite'; pathname: string }
  | { kind: 'redirect'; pathname: string };

const EN_PREFIX = /^\/en(?=\/|$)/;
const BN_PREFIX = /^\/bn(?=\/|$)/;

export const DEFAULT_PORTAL_HOSTS = ['customer.bhabaghure.com.bd', 'customer.localhost'];

export function portalHostsFrom(value: string | undefined): string[] {
  const hosts = (value ?? '').split(',').map((h) => h.trim().toLowerCase()).filter(Boolean);
  return hosts.length > 0 ? hosts : DEFAULT_PORTAL_HOSTS;
}

export function sectionForHost(hostHeader: string, portalHosts: string[]): Section {
  const hostname = hostHeader.split(':')[0].trim().toLowerCase();
  return portalHosts.includes(hostname) ? 'portal' : 'site';
}

export function resolveRoute(pathname: string, hostHeader: string, portalHosts: string[]): RouteDecision {
  if (BN_PREFIX.test(pathname)) {
    return { kind: 'redirect', pathname: pathname.replace(BN_PREFIX, '') || '/' };
  }

  const locale = EN_PREFIX.test(pathname) ? 'en' : 'bn';
  const rest = locale === 'en' ? pathname.replace(EN_PREFIX, '') : pathname;
  const tail = rest === '/' ? '' : rest;

  return { kind: 'rewrite', pathname: `/${locale}/${sectionForHost(hostHeader, portalHosts)}${tail}` };
}
