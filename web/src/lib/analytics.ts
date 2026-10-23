/**
 * Visitor analytics on the website (docs/visitor-analytics.md): page views, a few steps (the booking form opened), and
 * a heartbeat while the tab is visible — sent in the background to the API, which never stores the IP. No cookie: the
 * visit's random id lives in this tab's sessionStorage only. Nothing is sent when the browser asks not to be tracked
 * (Global Privacy Control or Do Not Track), and nothing here can break a page: every failure is swallowed.
 */

import { ga4Event, ga4PageView } from './ga4';

const STORE = 'bh_visit';
/** A tab idle this long starts a new visit (the API's default session window). */
const SESSION_MINUTES = 30;
/** The same page twice within this is one view (React Strict Mode, a router echo). */
const SAME_VIEW_MS = 2000;

type Visit = { id: string; last: number };
type Event = { key: string; type: 'page_view' | 'package_view' | 'booking_started'; path: string; subject?: string };

let lastView: { path: string; at: number } | null = null;
let started = false;

function optedOut(): boolean {
  if (typeof navigator === 'undefined') return true;
  const nav = navigator as Navigator & { globalPrivacyControl?: boolean };
  return nav.globalPrivacyControl === true || nav.doNotTrack === '1';
}

function uuid(): string {
  const random: Crypto = globalThis.crypto;
  if (typeof random.randomUUID === 'function') return random.randomUUID();
  // Older browsers: a version-4 UUID from random bytes.
  const bytes = random.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** This tab's visit id, renewed after 30 idle minutes; null when tracking is off or storage is blocked. */
function visit(touch: boolean): string | null {
  if (optedOut()) return null;
  try {
    const saved = JSON.parse(sessionStorage.getItem(STORE) ?? 'null') as Visit | null;
    const now = Date.now();
    const current = saved && now - saved.last < SESSION_MINUTES * 60_000 ? saved : { id: uuid(), last: now };
    if (touch || current !== saved) sessionStorage.setItem(STORE, JSON.stringify({ id: current.id, last: now }));
    if (current !== saved) started = false;
    return current.id;
  } catch {
    return null;
  }
}

function send(body: Record<string, unknown>, retry = true): void {
  const base = process.env.NEXT_PUBLIC_API_URL;
  if (!base) return;
  fetch(`${base}/api/v1/public/analytics/events`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
    keepalive: true,
    credentials: 'omit',
  }).catch(() => {
    // Once more after a moment: each event's key makes a repeat harmless.
    if (retry) setTimeout(() => send(body, false), 5000);
  });
}

function landingDetails(): Record<string, unknown> {
  const params = new URLSearchParams(window.location.search);
  const utm = { source: params.get('utm_source'), medium: params.get('utm_medium'), campaign: params.get('utm_campaign') };
  return { referrer: document.referrer || null, utm: utm.source || utm.medium || utm.campaign ? utm : null };
}

function locale(): 'bn' | 'en' {
  return window.location.pathname === '/en' || window.location.pathname.startsWith('/en/') ? 'en' : 'bn';
}

/** A page view of `path` (the address without query), once however often it is reported within two seconds. */
export function trackPageView(path: string = window.location.pathname): void {
  const now = Date.now();
  if (lastView && lastView.path === path && now - lastView.at < SAME_VIEW_MS) return;
  lastView = { path, at: now };
  // Google Analytics too, when connected and the visitor accepted cookies (ga4.ts).
  ga4PageView(path);
  const id = visit(true);
  if (!id) return;
  const first = !started;
  started = true;
  const event: Event = { key: uuid(), type: 'page_view', path };
  send({ session: id, events: [event], locale: locale(), ...(first ? landingDetails() : {}) });
}

/** Says a path is already counted, without counting it (a dialog closing back to the page it opened over). */
export function notePath(path: string): void {
  lastView = { path, at: Date.now() };
}

/** A step on the way to booking, e.g. "booking_started" with "package:<slug>". */
export function trackEvent(type: 'booking_started' | 'package_view', subject?: string): void {
  // GA4's own name for the first step of a purchase.
  if (type === 'booking_started') ga4Event('begin_checkout', { currency: 'BDT', items: subject ? [{ item_id: subject.replace(/^package:/, '') }] : [] });
  const id = visit(true);
  if (!id) return;
  send({ session: id, events: [{ key: uuid(), type, path: window.location.pathname, ...(subject ? { subject } : {}) }], locale: locale() });
}

/** Seconds the tab was visible since the last beat; the API counts at most a minute per beat. */
export function heartbeat(seconds: number): void {
  const id = visit(true);
  if (!id || seconds <= 0) return;
  send({ session: id, heartbeat: Math.round(seconds) }, false);
}

/**
 * The visit's id for a booking or form, so the API can record the conversion on this visit when it succeeds. Empty
 * when tracking is off.
 */
export function analyticsHeaders(): Record<string, string> {
  if (typeof window === 'undefined') return {};
  const id = visit(false);
  return id ? { 'X-Analytics-Session': id } : {};
}
