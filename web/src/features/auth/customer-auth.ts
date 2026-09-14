'use client';

export type AuthResult =
  | { ok: true; name: string }
  | { ok: false; reason: 'invalid_credentials' | 'unavailable' | 'failed' }
  /** Registration only: the number or email can't open an account online. The API words this neutrally. */
  | { ok: false; reason: 'contact_us'; field: 'phone' | 'email' };

const mock = () => process.env.NEXT_PUBLIC_FORMS_MOCK === '1';
const api = () => process.env.NEXT_PUBLIC_API_URL;

/**
 * Customer sign-in and registration against the Laravel customer guard (docs/phase-1-schema.md §6).
 * The API returns a short-lived access token in the body and sets the refresh token as an httpOnly
 * cookie on the API host, hence `credentials: 'include'`.
 */
export async function signInCustomer(input: { identifier: string; password: string }): Promise<AuthResult> {
  if (mock()) return { ok: true, name: input.identifier.split('@')[0] };
  return post('customer/auth/login', input);
}

export async function registerCustomer(input: { name: string; phone: string; email: string; password: string }): Promise<AuthResult> {
  if (mock()) return { ok: true, name: input.name };
  return post('customer/auth/register', input);
}

async function post(path: string, body: Record<string, string>): Promise<AuthResult> {
  const base = api();
  if (!base) return { ok: false, reason: 'unavailable' };
  try {
    const res = await fetch(`${base}/api/v1/${path}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    });
    if (res.status === 409) {
      const body = (await res.json().catch(() => null)) as { code?: string; field?: string } | null;
      if (body?.code === 'contact_us') return { ok: false, reason: 'contact_us', field: body.field === 'email' ? 'email' : 'phone' };
    }
    if (res.status === 401 || res.status === 422) return { ok: false, reason: 'invalid_credentials' };
    if (!res.ok) return { ok: false, reason: 'failed' };
    const data = (await res.json()) as { customer?: { name?: string } };
    return { ok: true, name: data.customer?.name ?? '' };
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
}
