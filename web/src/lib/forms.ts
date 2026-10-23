'use client';

import { analyticsHeaders } from './analytics';
import { ga4Event } from './ga4';

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
      // The visit's id, so an enquiry that succeeds counts as this visit's conversion (docs/visitor-analytics.md).
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...analyticsHeaders() },
      body: JSON.stringify(payload),
    });
    if (res.ok) {
      // Google Analytics, when connected and accepted: a lead for an enquiry or quote request, a sign-up for the
      // newsletter — after the API accepted it, never on the click (docs/visitor-analytics.md §GA4).
      const leads: Record<string, string> = { inquiries: 'enquiry', 'air-quotes': 'air_quote', 'hotel-quotes': 'hotel_quote' };
      if (path === 'newsletter') ga4Event('newsletter_signup');
      else if (leads[path]) ga4Event('generate_lead', { form: leads[path] });
      return { ok: true };
    }
    if (res.status === 429) return { ok: false, reason: 'rate_limited' };
    if (res.status === 422) return { ok: false, reason: 'invalid' };
    return { ok: false, reason: 'failed' };
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
}
