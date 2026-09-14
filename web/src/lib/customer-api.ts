'use client';

import { useCustomerSession, type SessionCustomer } from '@/state/customer-session';

/**
 * Customer sign-in by one-time code and the portal's authenticated calls (docs/phase-6-customer-portal.md §3.1, §5).
 *
 *   POST customer/auth/code    { phone }               → a code by SMS, or WhatsApp when SMS can't deliver
 *   POST customer/auth/verify  { phone, code, name? }  → access token in the body, refresh token as an httpOnly cookie
 *   POST customer/auth/refresh                          → a new access token from the cookie (credentials: 'include')
 *
 * The API host and the website/portal hosts are one site (bhabaghure.com.bd), so the SameSite=Strict refresh cookie
 * travels with these requests. The access token stays in memory; a reload restores the session through the cookie.
 */

const base = () => process.env.NEXT_PUBLIC_API_URL ?? '';

/** Not a credential: only tells the website whether a restore is worth a request (the cookie itself is unreadable). */
const HINT_KEY = 'bh-customer-session';

type TokenBody = { access_token: string; customer: SessionCustomer };

export type SendCodeResult =
  | { ok: true; retryAfter: number }
  | { ok: false; reason: 'throttled'; retryAfter: number }
  | { ok: false; reason: 'undeliverable' | 'invalid_phone' | 'rate_limited' | 'unavailable' | 'failed' };

export type VerifyResult =
  | { ok: true; customer: SessionCustomer }
  | { ok: false; reason: 'invalid_code' | 'name_required' | 'portal_disabled' | 'rate_limited' | 'unavailable' | 'failed' };

async function post(path: string, body: Record<string, unknown> | null, locale: string): Promise<Response | null> {
  if (!base()) return null;
  try {
    return await fetch(`${base()}/api/v1/customer/auth/${path}`, {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Locale': locale },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    return null;
  }
}

export async function sendCode(phone: string, locale: string): Promise<SendCodeResult> {
  const res = await post('code', { phone, locale }, locale);
  if (!res) return { ok: false, reason: 'unavailable' };
  const body = (await res.json().catch(() => null)) as { data?: { retry_after?: number }; code?: string; retry_after?: number } | null;
  if (res.status === 202) return { ok: true, retryAfter: body?.data?.retry_after ?? 60 };
  if (body?.code === 'throttled') return { ok: false, reason: 'throttled', retryAfter: body.retry_after ?? 60 };
  if (body?.code === 'code_undeliverable') return { ok: false, reason: 'undeliverable' };
  if (res.status === 429) return { ok: false, reason: 'rate_limited' };
  if (res.status === 422) return { ok: false, reason: 'invalid_phone' };
  return { ok: false, reason: 'failed' };
}

export async function verifyCode(input: { phone: string; code: string; name?: string }, locale: string): Promise<VerifyResult> {
  const res = await post('verify', { ...input, locale }, locale);
  if (!res) return { ok: false, reason: 'unavailable' };
  const body = (await res.json().catch(() => null)) as (Partial<TokenBody> & { code?: string }) | null;
  if (res.ok && body?.access_token && body.customer) {
    establish(body as TokenBody);
    return { ok: true, customer: body.customer };
  }
  if (body?.code === 'name_required' || body?.code === 'portal_disabled') return { ok: false, reason: body.code };
  if (res.status === 429) return { ok: false, reason: 'rate_limited' };
  if (res.status === 422) return { ok: false, reason: 'invalid_code' };
  return { ok: false, reason: 'failed' };
}

let restoring: Promise<boolean> | null = null;

/**
 * Exchanges the refresh cookie for an access token. Concurrent callers share one request, so a page mounting several
 * components rotates the cookie once. `onlyIfHinted` skips the request for visitors who never signed in on this device.
 */
export function restoreSession(locale: string, { onlyIfHinted = false } = {}): Promise<boolean> {
  if (onlyIfHinted && !readHint()) {
    useCustomerSession.getState().clear();
    return Promise.resolve(false);
  }
  restoring ??= (async () => {
    const res = await post('refresh', null, locale);
    const body = res?.ok ? ((await res.json().catch(() => null)) as TokenBody | null) : null;
    if (body?.access_token && body.customer) {
      establish(body);
      return true;
    }
    // A network failure isn't a signed-out answer: keep the hint so the next page load tries again.
    if (res) writeHint(false);
    useCustomerSession.getState().clear();
    return false;
  })().finally(() => {
    restoring = null;
  });
  return restoring;
}

export async function signOut(locale: string): Promise<void> {
  const token = useCustomerSession.getState().accessToken;
  if (base()) {
    await fetch(`${base()}/api/v1/customer/auth/logout`, {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json', 'X-Locale': locale, ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    }).catch(() => null);
  }
  writeHint(false);
  useCustomerSession.getState().clear();
}

export type PortalFailure = { ok: false; reason: 'not_found' | 'signed_out' | 'invalid' | 'conflict' | 'rate_limited' | 'unavailable' | 'failed'; code?: string; message?: string; errors?: Record<string, string[]> };
export type PortalResult<T> = { ok: true; data: T } | PortalFailure;

/**
 * An authenticated portal call. On a 401 (the 15-minute access token ran out) the session is restored once and the
 * call repeated; if that fails the customer is signed out and sees the sign-in screen.
 */
export async function portalCall<T>(path: string, locale: string, init: RequestInit = {}): Promise<PortalResult<T>> {
  if (!base()) return { ok: false, reason: 'unavailable' };
  const send = (token: string | null) =>
    fetch(`${base()}/api/v1/${path}`, {
      ...init,
      headers: {
        Accept: 'application/json',
        'X-Locale': locale,
        ...(init.body && !(init.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

  let res: Response;
  try {
    res = await send(useCustomerSession.getState().accessToken);
    if (res.status === 401) {
      if (!(await restoreSession(locale))) return { ok: false, reason: 'signed_out' };
      res = await send(useCustomerSession.getState().accessToken);
    }
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
  if (res.status === 204) return { ok: true, data: undefined as T };
  const body = (await res.json().catch(() => null)) as { data?: T; code?: string; message?: string; errors?: Record<string, string[]> } | null;
  if (res.ok) return { ok: true, data: (body?.data ?? body) as T };
  const detail = { code: body?.code, message: body?.message, errors: body?.errors };
  if (res.status === 401) return { ok: false, reason: 'signed_out' };
  if (res.status === 404) return { ok: false, reason: 'not_found', ...detail };
  if (res.status === 409) return { ok: false, reason: 'conflict', ...detail };
  if (res.status === 422) return { ok: false, reason: 'invalid', ...detail };
  if (res.status === 429) return { ok: false, reason: 'rate_limited', ...detail };
  return { ok: false, reason: 'failed', ...detail };
}

function establish(body: TokenBody) {
  writeHint(true);
  useCustomerSession.getState().establish(body.access_token, body.customer);
}

function readHint(): boolean {
  try {
    return localStorage.getItem(HINT_KEY) === '1';
  } catch {
    return false;
  }
}

function writeHint(signedIn: boolean) {
  try {
    if (signedIn) localStorage.setItem(HINT_KEY, '1');
    else localStorage.removeItem(HINT_KEY);
  } catch {
    // Private mode: the website header just won't know about the session until the portal is opened.
  }
}
