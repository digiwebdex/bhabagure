'use client';

/**
 * Sends a customer's review with its trip photos (docs/customer-reviews.md) to POST /public/reviews as multipart. On a
 * refusal the API's own sentences come back per field, so the form shows them under the right control.
 */
export type ReviewResult =
  | { ok: true }
  | { ok: false; reason: 'invalid'; errors: Record<string, string> }
  | { ok: false; reason: 'rate_limited' | 'unavailable' | 'failed' };

export async function submitReview(form: FormData): Promise<ReviewResult> {
  if (process.env.NEXT_PUBLIC_FORMS_MOCK === '1') {
    await new Promise((resolve) => setTimeout(resolve, 500));
    return { ok: true };
  }
  const base = process.env.NEXT_PUBLIC_API_URL;
  if (!base) return { ok: false, reason: 'unavailable' };

  try {
    const res = await fetch(`${base}/api/v1/public/reviews`, { method: 'POST', headers: { Accept: 'application/json' }, body: form });
    if (res.ok) return { ok: true };
    if (res.status === 429) return { ok: false, reason: 'rate_limited' };
    if (res.status === 422) {
      const body = (await res.json().catch(() => null)) as { errors?: Record<string, string[]> } | null;
      // "photos.2" and "photos" both belong to the photos control.
      const errors: Record<string, string> = {};
      for (const [key, messages] of Object.entries(body?.errors ?? {})) {
        const field = key.split('.')[0];
        errors[field] ??= messages[0] ?? '';
      }
      return { ok: false, reason: 'invalid', errors };
    }
    return { ok: false, reason: 'failed' };
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
}
