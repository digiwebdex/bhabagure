import { NextResponse, type NextRequest } from 'next/server';

import { TOUR_PREVIEW_COOKIE, TOUR_PREVIEW_MAX_AGE } from '@/lib/tour-preview-cookie';

/**
 * A staff preview link's token, taken from its #hash by the preview page and posted here (docs/tour-landing-pages.md §6):
 * checked with the API, then kept in an HttpOnly cookie, so it is never in an address, a log or analytics. A token the
 * API refuses clears the cookie and sets nothing.
 */
export async function POST(request: NextRequest) {
  const body = (await request.json().catch(() => null)) as { token?: unknown } | null;
  const token = typeof body?.token === 'string' ? body.token : '';
  const base = process.env.API_INTERNAL_URL || process.env.API_URL;
  const valid =
    !!base &&
    /^[A-Za-z0-9_-]{8,120}\.[A-Za-z0-9_-]{8,120}$/.test(token) &&
    (await fetch(`${base}/api/v1/public/tour-pages/preview`, { headers: { 'X-Preview-Token': token, Accept: 'application/json' }, cache: 'no-store' })
      .then((res) => res.ok)
      .catch(() => false));

  const response = new NextResponse(null, { status: valid ? 204 : 404, headers: { 'Cache-Control': 'no-store' } });
  if (valid) {
    response.cookies.set(TOUR_PREVIEW_COOKIE, token, { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'strict', path: '/', maxAge: TOUR_PREVIEW_MAX_AGE });
  } else {
    response.cookies.delete(TOUR_PREVIEW_COOKIE);
  }
  return response;
}
