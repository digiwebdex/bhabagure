#!/usr/bin/env node
/**
 * Local wallet databases (docs/phase-7-hr-attendance-bonus-wallet.md §8), for the project-local MySQL on port 3307:
 *
 *   node scripts/wallet-db-local.mjs
 *
 * Creates bhabaghure_wallet, bhabaghure_wallet_testing and bhabaghure_wallet_e2e, and the MySQL user bhabaghure_wallet
 * with rights on those three only. The company user gets nothing on them, and the wallet user nothing on the company
 * databases. A generated password and a wallet encryption key go into api/.env when they aren't there yet; neither is
 * printed. Safe to run again: it never replaces an existing password or key.
 *
 * The VPS has its own script, deploy/wallet-database.sh.
 */
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const ENV_FILE = resolve(ROOT, 'api/.env')
const MYSQL = process.env.MYSQL_BIN ?? 'C:/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe'
const DATABASES = ['bhabaghure_wallet', 'bhabaghure_wallet_testing', 'bhabaghure_wallet_e2e']
const USER = 'bhabaghure_wallet'
const HOST = '127.0.0.1'

if (!existsSync(ENV_FILE)) throw new Error('api/.env is missing: copy api/.env.example first.')
const env = readFileSync(ENV_FILE, 'utf8')
const get = (key) => env.match(new RegExp(`^${key}=(.*)$`, 'm'))?.[1]?.trim() ?? ''

const password = get('WALLET_DB_PASSWORD') || randomBytes(24).toString('base64url')
const key = get('WALLET_KEY') || `base64:${randomBytes(32).toString('base64')}`

// SQL goes to mysql on stdin, so the password never appears on a command line.
const sql = [
  ...DATABASES.map((name) => `CREATE DATABASE IF NOT EXISTS \`${name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`),
  `CREATE USER IF NOT EXISTS '${USER}'@'${HOST}' IDENTIFIED BY '${password}';`,
  `ALTER USER '${USER}'@'${HOST}' IDENTIFIED BY '${password}';`,
  ...DATABASES.map((name) => `GRANT ALL PRIVILEGES ON \`${name}\`.* TO '${USER}'@'${HOST}';`),
  'FLUSH PRIVILEGES;',
].join('\n')
execFileSync(MYSQL, ['-u', 'root', '-h', HOST, '-P', get('DB_PORT') || '3307', '--protocol=TCP'], { input: sql, stdio: ['pipe', 'inherit', 'inherit'] })

const additions = {
  WALLET_DB_HOST: get('WALLET_DB_HOST') || get('DB_HOST') || HOST,
  WALLET_DB_PORT: get('WALLET_DB_PORT') || get('DB_PORT') || '3307',
  WALLET_DB_DATABASE: get('WALLET_DB_DATABASE') || 'bhabaghure_wallet',
  WALLET_DB_USERNAME: get('WALLET_DB_USERNAME') || USER,
  WALLET_DB_PASSWORD: password,
  WALLET_KEY: key,
  WALLET_COOKIE_SECURE: get('WALLET_COOKIE_SECURE') || 'false',
}
let next = env
for (const [name, value] of Object.entries(additions)) {
  if (new RegExp(`^${name}=`, 'm').test(next)) next = next.replace(new RegExp(`^${name}=.*$`, 'm'), `${name}=${value}`)
  else next = `${next.replace(/\n*$/, '\n')}${name}=${value}\n`
}
if (next !== env) writeFileSync(ENV_FILE, next)

console.log(`Wallet databases ready: ${DATABASES.join(', ')}; user ${USER}@${HOST}. api/.env has the WALLET_* settings (values not shown).`)
