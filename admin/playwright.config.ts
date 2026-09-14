import { defineConfig } from '@playwright/test'

import { E2E_API_URL, e2eApiServer, isMainProcess, resetE2eDatabase, writeE2eEnv } from '../scripts/e2e-api.mjs'

/**
 * End-to-end checks of the admin against the real Laravel API, on the local-only bhabaghure_e2e database
 * (scripts/e2e-api.mjs), so the dev database is never touched.
 *
 *   npm run test:e2e --workspace admin
 *
 * Needs the local MySQL on 3307 (api/README.md) and uses the installed Chrome.
 */
const ADMIN_PORT = 5174

if (isMainProcess()) {
  writeE2eEnv({ origins: [`http://localhost:${ADMIN_PORT}`], adminUrl: `http://localhost:${ADMIN_PORT}` })
  resetE2eDatabase()
}

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  // One API process (PHP's built-in server) and shared data: run in order.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  timeout: 60_000,
  use: {
    baseURL: `http://localhost:${ADMIN_PORT}`,
    channel: 'chrome',
    trace: 'retain-on-failure',
  },
  webServer: [
    e2eApiServer(),
    {
      command: `npx vite --port ${ADMIN_PORT} --strictPort`,
      env: { VITE_API_URL: E2E_API_URL, VITE_SITE_URL: 'http://localhost:3000' },
      url: `http://localhost:${ADMIN_PORT}`,
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
})
