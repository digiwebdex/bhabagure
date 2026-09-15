import { randomBytes } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { artisan } from '../../scripts/e2e-api.mjs'

const STATE_DIR = resolve(import.meta.dirname, '.state')

/**
 * The e2e databases were rebuilt when the config loaded. Here: the super admin who owns the wallet, and an admin who
 * doesn't, with a random password written to e2e/.state (git-ignored).
 */
export default function globalSetup() {
  const password = randomBytes(12).toString('base64url')
  const php = [
    `$password = '${password}';`,
    `App\\Models\\Staff::query()->create(['employee_code' => 'W-1', 'name' => 'E2E Owner', 'email' => 'owner@e2e.test', 'password' => $password, 'status' => 'active', 'must_change_password' => false])->assignRole('super_admin');`,
    `App\\Models\\Staff::query()->create(['employee_code' => 'W-2', 'name' => 'E2E Admin', 'email' => 'admin@e2e.test', 'password' => $password, 'status' => 'active', 'must_change_password' => false])->assignRole('admin');`,
    'echo "ok";',
  ].join(' ')
  artisan('tinker', `--execute=${php}`)

  mkdirSync(STATE_DIR, { recursive: true })
  writeFileSync(resolve(STATE_DIR, 'staff.json'), JSON.stringify({ password }))
}
