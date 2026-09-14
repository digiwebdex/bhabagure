'use client';

export type SubmitResult = { ok: true } | { ok: false; reason: 'unavailable' | 'rate_limited' | 'invalid' | 'failed' };

/**
 * Sends a public form (inquiry, air-ticket quote, newsletter) to the Laravel API.
 *
 * NEXT_PUBLIC_API_URL      the API origin; forms post there directly (CORS allow-listed), so the
 *                          API's rate limiter sees the visitor's IP rather than the web server's.
 * NEXT_PUBLIC_FORMS_MOCK=1 local only: pretend the API accepted the form, to check success states
 *                          before the API exists. Never set on the server.
 */
export async function submitPublicForm(path: string, payload: Record<string, unknown>): Promise<SubmitResult> {
  if (process.env.NEXT_PUBLIC_FORMS_MOCK === '1') {
    await new Promise((resolve) => setTimeout(resolve, 500));
    return { ok: true };
  }
  const base = process.env.NEXT_PUBLIC_API_URL;
  if (!base) return { ok: false, reason: 'unavailable' };

  try {
    const res = await fetch(`${base}/api/v1/public/${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });
    if (res.ok) return { ok: true };
    if (res.status === 429) return { ok: false, reason: 'rate_limited' };
    if (res.status === 422) return { ok: false, reason: 'invalid' };
    return { ok: false, reason: 'failed' };
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
}
