import { defineConfig } from '@playwright/test';

import { E2E_API_URL, E2E_REVALIDATE_SECRET, e2eApiServer, isMainProcess, resetE2eDatabase, writeE2eEnv } from '../scripts/e2e-api.mjs';

/**
 * End-to-end checks of the website on live data: a local-only API on the bhabaghure_e2e database
 * (scripts/e2e-api.mjs), then a production build of the site against it — pages are prerendered from the API,
 * forms and sign-in post to it for real, and CMS saves refresh the site's cache through /api/revalidate.
 *
 *   npm run test:e2e --workspace web
 *
 * Needs the local MySQL on 3307 (api/README.md) and uses the installed Chrome. The build takes a minute or two.
 */
const PORT = 3100;
const SITE_URL = `http://localhost:${PORT}`;

if (isMainProcess()) {
  writeE2eEnv({ origins: [SITE_URL], webUrl: SITE_URL });
  resetE2eDatabase();
}

export default defineConfig({
  testDir: './e2e',
  // Tests that write (forms, CMS saves) share one API and database.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  timeout: 60_000,
  use: {
    baseURL: SITE_URL,
    channel: 'chrome',
    trace: 'retain-on-failure',
  },
  // Started in order: the site's build reads content from the API.
  webServer: [
    e2eApiServer(),
    {
      command: `npm run build && npx next start -p ${PORT}`,
      env: {
        CONTENT_SOURCE: 'api',
        CONTENT_DEMO: '0',
        API_URL: E2E_API_URL,
        NEXT_PUBLIC_API_URL: E2E_API_URL,
        NEXT_PUBLIC_FORMS_MOCK: '0',
        NEXT_PUBLIC_SITE_URL: SITE_URL,
        NEXT_PUBLIC_PORTAL_URL: `http://customer.localhost:${PORT}`,
        REVALIDATE_SECRET: E2E_REVALIDATE_SECRET,
      },
      url: SITE_URL,
      reuseExistingServer: false,
      timeout: 300_000,
    },
  ],
});
