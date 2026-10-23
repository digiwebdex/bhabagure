/**
 * Google Analytics 4 (docs/visitor-analytics.md §GA4), when the super admin has connected it: Google's tag is loaded
 * only after the visitor accepts cookies in the banner, and never for a browser that asks not to be tracked. Page views
 * and the steps below are sent by hand, so a page counts once (the same rules as our own analytics). Never a name,
 * phone number, email or form content.
 */

const CHOICE = 'bh_cookie_choice';
export const COOKIE_SETTINGS_EVENT = 'bh:cookie-settings';

type Gtag = (...args: unknown[]) => void;
declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: Gtag;
  }
}

let measurementId: string | null = null;
let loaded = false;

export type CookieChoice = 'granted' | 'denied';

export function optedOutByBrowser(): boolean {
  if (typeof navigator === 'undefined') return true;
  const nav = navigator as Navigator & { globalPrivacyControl?: boolean };
  return nav.globalPrivacyControl === true || nav.doNotTrack === '1';
}

/** The visitor's answer to the banner, kept in this browser only; null until they answer. */
export function cookieChoice(): CookieChoice | null {
  try {
    const value = localStorage.getItem(CHOICE);
    return value === 'granted' || value === 'denied' ? value : null;
  } catch {
    return null;
  }
}

export function saveCookieChoice(choice: CookieChoice): void {
  try {
    localStorage.setItem(CHOICE, choice);
  } catch {
    // Storage blocked: the banner asks again next time.
  }
}

/** Takes the connected Measurement ID (null: not connected) and loads GA4 if the visitor has already accepted. */
export function setupGa4(id: string | null): void {
  measurementId = id;
  if (id && cookieChoice() === 'granted' && !optedOutByBrowser()) loadGa4();
}

/** Loads Google's tag once, after consent. The first page view is sent by the caller. */
export function loadGa4(): void {
  if (loaded || !measurementId || typeof document === 'undefined' || optedOutByBrowser()) return;
  loaded = true;
  window.dataLayer = window.dataLayer ?? [];
  window.gtag = function gtag() {
    // gtag.js reads the arguments object itself.
    // eslint-disable-next-line prefer-rest-params
    window.dataLayer!.push(arguments);
  };
  window.gtag('js', new Date());
  window.gtag('config', measurementId, { send_page_view: false });
  const script = document.createElement('script');
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
  document.head.append(script);
}

/** After "decline" following an earlier "accept": Google's cookies on this site are removed. */
export function clearGa4Cookies(): void {
  const host = window.location.hostname;
  const domains = ['', host, `.${host}`, `.${host.split('.').slice(-3).join('.')}`];
  for (const name of document.cookie.split(';').map((c) => c.split('=')[0].trim())) {
    if (name === '_ga' || name.startsWith('_ga_') || name === '_gid') {
      for (const domain of domains) document.cookie = `${name}=; Max-Age=0; path=/${domain ? `; domain=${domain}` : ''}`;
    }
  }
}

export function ga4Enabled(): boolean {
  return loaded;
}

/** A GA4 event, if GA4 is loaded; otherwise nothing. */
export function ga4Event(name: string, params: Record<string, unknown> = {}): void {
  if (loaded && window.gtag) window.gtag('event', name, params);
}

export function ga4PageView(path: string): void {
  ga4Event('page_view', { page_location: `${window.location.origin}${path}`, page_path: path, page_title: document.title });
}
