// Shared by admin/playwright.config.ts and web/playwright.config.ts: a second, local-only API instance on its own
// database (bhabaghure_e2e), so end-to-end tests never touch the dev database. Needs the local MySQL on 3307.
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'

// Found from the working directory (admin/ or web/) rather than import.meta: Playwright may load this file as CommonJS.
function findApiDir(start) {
  for (let dir = start; ; dir = dirname(dir)) {
    if (existsSync(resolve(dir, 'api/artisan'))) return resolve(dir, 'api')
    if (dirname(dir) === dir) throw new Error('Could not find api/artisan above ' + start)
  }
}

export const API_DIR = findApiDir(process.cwd())
export const E2E_API_PORT = 8001
export const E2E_API_URL = `http://localhost:${E2E_API_PORT}`
export const E2E_REVALIDATE_SECRET = 'e2e-revalidate-secret'

/** Runs artisan against bhabaghure_e2e (APP_ENV=e2e loads api/.env.e2e). */
export function artisan(...args) {
  return execFileSync('php', ['artisan', ...args], { cwd: API_DIR, env: { ...process.env, APP_ENV: 'e2e' }, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
}

/**
 * Writes api/.env.e2e (git-ignored by `.env.*`): the dev .env with the e2e database, CORS for the given origins,
 * and — when a website URL is given — revalidation calls to that website. `php artisan serve` drops most
 * environment variables, which is why the overrides live in a file.
 */
export function writeE2eEnv({ origins, webUrl = '' }) {
  const base = existsSync(resolve(API_DIR, '.env')) ? readFileSync(resolve(API_DIR, '.env'), 'utf8') : ''
  const overrides = {
    APP_ENV: 'e2e',
    APP_URL: E2E_API_URL,
    DB_DATABASE: 'bhabaghure_e2e',
    CORS_ALLOWED_ORIGINS: origins.join(','),
    AUTH_REFRESH_COOKIE_SECURE: 'false',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    WEB_URL: webUrl || 'http://localhost:3000',
    WEB_REVALIDATE_URL: webUrl ? `${webUrl}/api/revalidate` : '',
    REVALIDATE_SECRET: E2E_REVALIDATE_SECRET,
    DB_GUARD_TRIGGERS: 'false',
    // Payments go to the local SSLCommerz stand-in; passport OCR is off, so the "type manually" path is what runs.
    SSLCOMMERZ_MODE: 'fake',
    PASSPORT_OCR_PROVIDER: 'none',
    // WhatsApp through the local stand-in (each message lands in api/storage/logs/whatsapp-fake.log), unpaced;
    // notification emails go to the log mailer.
    WASENDER_MODE: 'fake',
    WASENDER_SECONDS_BETWEEN_SENDS: '0',
    WASENDER_JITTER_SECONDS: '0',
    NOTIFICATIONS_EMAIL: 'true',
    MAIL_MAILER: 'log',
    // SMS through its stand-in too (api/storage/logs/sms-fake.log).
    BULKSMSBD_MODE: 'fake',
  }
  const lines = base
    .split(/\r?\n/)
    .filter((line) => !Object.keys(overrides).some((key) => line.startsWith(`${key}=`)))
    .concat(Object.entries(overrides).map(([key, value]) => `${key}=${value}`))
  writeFileSync(resolve(API_DIR, '.env.e2e'), `# Generated for local end-to-end tests (scripts/e2e-api.mjs).\n${lines.join('\n')}\n`)
}

/** Rebuilds bhabaghure_e2e from migrations and seeders. Refuses any other database. */
export function resetE2eDatabase() {
  const database = artisan('tinker', '--execute=echo config("database.connections.mysql.database");').trim().split(/\r?\n/).pop()
  if (database !== 'bhabaghure_e2e') throw new Error(`E2E must run against bhabaghure_e2e, got "${database}". Check api/.env.e2e.`)
  artisan('migrate:fresh', '--seed', '--force')
}

export const e2eApiServer = () => ({
  command: `php artisan serve --port=${E2E_API_PORT}`,
  cwd: API_DIR,
  env: { APP_ENV: 'e2e' },
  url: `${E2E_API_URL}/up`,
  reuseExistingServer: false,
  timeout: 60_000,
})

/** Playwright loads the config in every worker too; the database is reset once, in the main process. */
export const isMainProcess = () => process.env.TEST_WORKER_INDEX === undefined
