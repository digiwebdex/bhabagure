import { NextResponse, type NextRequest } from 'next/server';

import { TOUR_PREVIEW_COOKIE } from '@/lib/tour-preview-cookie';

/**
 * The previewed page printed as its brochure, for staff (docs/tour-landing-pages.md §6, §7): the preview's HttpOnly
 * cookie goes to the API as its X-Preview-Token header — never in an address — and the PDF comes back as it is. Nothing
 * is cached; without a valid preview there is nothing to give.
 */
export async function GET(request: NextRequest) {
  const token = request.cookies.get(TOUR_PREVIEW_COOKIE)?.value ?? '';
  const locale = request.nextUrl.searchParams.get('locale') === 'en' ? 'en' : 'bn';
  const base = process.env.API_INTERNAL_URL || process.env.API_URL;
  const noStore = { 'Cache-Control': 'no-store', 'X-Robots-Tag': 'noindex, nofollow' };
  if (!base || !/^[A-Za-z0-9_-]{8,120}\.[A-Za-z0-9_-]{8,120}$/.test(token)) {
    return NextResponse.json({ code: 'expired' }, { status: 404, headers: noStore });
  }

  const res = await fetch(`${base}/api/v1/public/tour-pages/preview/brochure?locale=${locale}`, {
    headers: { 'X-Preview-Token': token, Accept: 'application/pdf, application/json' },
    cache: 'no-store',
  }).catch(() => null);
  if (!res) return NextResponse.json({ code: 'render_failed' }, { status: 503, headers: noStore });
  if (!res.ok) {
    const code = res.status === 404 ? 'expired' : res.status === 429 ? 'rate_limited' : 'render_failed';
    return NextResponse.json({ code }, { status: res.status, headers: noStore });
  }

  return new NextResponse(res.body, {
    status: 200,
    headers: {
      ...noStore,
      'Content-Type': 'application/pdf',
      'Content-Disposition': res.headers.get('Content-Disposition') ?? 'attachment; filename="bhabaghure-preview.pdf"',
    },
  });
}
