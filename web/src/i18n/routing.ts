import { defineRouting } from 'next-intl/routing';

// URL handling itself lives in src/proxy.ts (host + locale). This config drives
// next-intl's locale-aware <Link> so generated hrefs match: Bangla unprefixed, English under /en.
export const routing = defineRouting({
  locales: ['bn', 'en'],
  defaultLocale: 'bn',
  localePrefix: 'as-needed',
  localeDetection: false,
});

export type AppLocale = (typeof routing.locales)[number];
