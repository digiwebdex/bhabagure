import type { NextConfig } from 'next';
import createNextIntlPlugin from 'next-intl/plugin';

const withNextIntl = createNextIntlPlugin();

type RemotePattern = NonNullable<NonNullable<NextConfig['images']>['remotePatterns']>[number];

// Object patterns without `search`: a URL-object pattern would only allow URLs with no query string.
const remote = (origin: string, pathname: string): RemotePattern => {
  const url = new URL(origin);
  return { protocol: url.protocol.replace(':', '') as 'http' | 'https', hostname: url.hostname, port: url.port, pathname };
};

const nextConfig: NextConfig = {
  // deploy/deploy.sh builds into a separate directory while the live server keeps serving .next, then swaps them.
  distDir: process.env.NEXT_DIST_DIR || '.next',
  // Workspace packages that ship TypeScript source.
  transpilePackages: ['@bhabaghure/format', '@bhabaghure/pricing'],
  poweredByHeader: false,
  // Every page of the website and portal: never framed (no clickjacking of booking, payment or sign-in), no MIME
  // sniffing, and only the origin in the Referer sent to other sites (booking links carry a private token in the hash;
  // share links in the path). HSTS is Cloudflare's setting (docs/handover.md §6.1).
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Content-Security-Policy', value: "frame-ancestors 'none'" },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        ],
      },
    ];
  },
  images: {
    remotePatterns: [
      // CMS uploads, served from the API host (e.g. https://api.bhabaghure.com.bd/storage/…).
      ...(process.env.API_URL ? [remote(process.env.API_URL, '/storage/**')] : []),
      // Stock placeholders from the design until the client uploads real photos through the CMS.
      remote('https://images.pexels.com', '/photos/**'),
      // YouTube's own stills for the travel host's picked videos (docs/travel-host.md).
      remote('https://i.ytimg.com', '/vi/**'),
    ],
  },
};

export default withNextIntl(nextConfig);
