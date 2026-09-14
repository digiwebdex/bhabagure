import { NextResponse, type NextRequest } from 'next/server';

import { portalHostsFrom, resolveRoute } from '@/lib/host-routing';

const portalHosts = portalHostsFrom(process.env.PORTAL_HOSTS);

export function proxy(request: NextRequest) {
  // nginx forwards the original Host header (proxy_set_header Host $host).
  const decision = resolveRoute(request.nextUrl.pathname, request.headers.get('host') ?? '', portalHosts);

  const url = request.nextUrl.clone();
  url.pathname = decision.pathname;

  return decision.kind === 'redirect' ? NextResponse.redirect(url, 308) : NextResponse.rewrite(url);
}

export const config = {
  // Everything except Next internals, API routes and files with an extension
  // (images, fonts, manifest.webmanifest, sw.js, robots.txt …).
  matcher: ['/((?!api|_next/static|_next/image|.*\\..*).*)'],
};
