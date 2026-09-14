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
  // Workspace packages that ship TypeScript source.
  transpilePackages: ['@bhabaghure/format', '@bhabaghure/pricing'],
  poweredByHeader: false,
  images: {
    remotePatterns: [
      // CMS uploads, served from the API host (e.g. https://api.bhabaghure.com.bd/storage/…).
      ...(process.env.API_URL ? [remote(process.env.API_URL, '/storage/**')] : []),
      // Stock placeholders from the design until the client uploads real photos through the CMS.
      remote('https://images.pexels.com', '/photos/**'),
    ],
  },
};

export default withNextIntl(nextConfig);
