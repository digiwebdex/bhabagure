import { defineConfig } from '@playwright/test'

import { E2E_API_URL, e2eApiServer, isMainProcess, resetE2eDatabase, writeE2eEnv } from '../scripts/e2e-api.mjs'

/**
 * End-to-end checks of the wallet against the real Laravel API, on bhabaghure_e2e and bhabaghure_wallet_e2e
 * (scripts/e2e-api.mjs), never the dev databases.
 *
 *   npm run test:e2e --workspace wallet
 *
 * Needs the local MySQL on 3307 with the wallet databases (node scripts/wallet-db-local.mjs).
 */
const WALLET_PORT = 5176

if (isMainProcess()) {
  writeE2eEnv({ origins: [] })
  resetE2eDatabase()
}

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  timeout: 60_000,
  use: {
    baseURL: `http://localhost:${WALLET_PORT}`,
    channel: 'chrome',
    trace: 'retain-on-failure',
  },
  webServer: [
    e2eApiServer(),
    {
      command: `npx vite --port ${WALLET_PORT} --strictPort`,
      env: { WALLET_API_URL: E2E_API_URL },
      url: `http://localhost:${WALLET_PORT}`,
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
})
