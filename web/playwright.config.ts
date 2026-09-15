import { defineConfig } from '@playwright/test';

import { E2E_API_URL, E2E_CHANNEL, E2E_REVALIDATE_SECRET, e2eApiServer, isMainProcess, resetE2eDatabase, writeE2eEnv } from '../scripts/e2e-api.mjs';

import { BROWSER_API_URL, PORT, PORTAL_URL, SITE_URL, TEST_DOMAIN } from './e2e/hosts';

/**
 * End-to-end checks of the website and the customer portal on live data: a local-only API on the bhabaghure_e2e database
 * (scripts/e2e-api.mjs), then a production build of the site against it — pages are prerendered from the API,
 * forms and sign-in post to it for real, and CMS saves refresh the site's cache through /api/revalidate.
 *
 *   npm run test:e2e --workspace web
 *
 * Needs the local MySQL on 3307 (api/README.md) and uses the installed Chrome. The build takes a minute or two.
 *
 * The portal signs in with a SameSite=Strict refresh cookie on the API host, which only travels between hosts of one
 * site — customer.bhabaghure.com.bd and api.bhabaghure.com.bd in production. localhost can't model that
 * (customer.localhost and localhost are different sites), so the browser maps *.e2e.example.com to this machine:
 * the portal runs at customer.e2e.example.com and calls the API at api.e2e.example.com. Nothing leaves the machine.
 */
if (isMainProcess()) {
  writeE2eEnv({ origins: [SITE_URL, PORTAL_URL], webUrl: SITE_URL, portalUrl: PORTAL_URL });
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
    channel: E2E_CHANNEL,
    trace: 'retain-on-failure',
    launchOptions: {
      args: [`--host-resolver-rules=MAP *.${TEST_DOMAIN} 127.0.0.1`, '--disable-features=HttpsUpgrades,HttpsFirstBalancedModeAutoEnable'],
    },
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
        // What the browser calls: the same local API, under the test site's name.
        NEXT_PUBLIC_API_URL: BROWSER_API_URL,
        NEXT_PUBLIC_FORMS_MOCK: '0',
        NEXT_PUBLIC_SITE_URL: SITE_URL,
        NEXT_PUBLIC_PORTAL_URL: PORTAL_URL,
        PORTAL_HOSTS: `customer.${TEST_DOMAIN},customer.localhost`,
        REVALIDATE_SECRET: E2E_REVALIDATE_SECRET,
      },
      url: SITE_URL,
      reuseExistingServer: false,
      timeout: 300_000,
    },
  ],
});
