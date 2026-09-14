import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { artisan } from '../../scripts/e2e-api.mjs'

const STATE_DIR = resolve(import.meta.dirname, '.state')

/**
 * The e2e database was rebuilt when the config loaded. Here: one staff account per role with a random password
 * (written to e2e/.state, git-ignored), and a real photo for the upload tests.
 */
export default function globalSetup() {
  const password = randomBytes(12).toString('base64url')
  const roles = ['super_admin', 'admin', 'tour_operator', 'sales_agent']
  const php = [
    `$password = '${password}';`,
    ...roles.map(
      (role, index) =>
        `$s = App\\Models\\Staff::query()->create(['employee_code' => 'E2E-${index + 1}', 'name' => 'E2E ${role}', 'email' => '${role}@e2e.test', 'password' => $password, 'status' => 'active', 'must_change_password' => false]); $s->assignRole('${role}');`,
    ),
    `App\\Models\\Staff::query()->create(['employee_code' => 'E2E-9', 'name' => 'E2E New Hire', 'email' => 'new.hire@e2e.test', 'password' => $password, 'status' => 'active', 'must_change_password' => true])->assignRole('admin');`,
    'echo "ok";',
  ].join(' ')
  artisan('tinker', `--execute=${php}`)

  mkdirSync(STATE_DIR, { recursive: true })
  writeFileSync(resolve(STATE_DIR, 'staff.json'), JSON.stringify({ password }))

  // A real 1800×1200 JPEG, made with PHP's GD.
  execFileSync('php', ['-r', `$i = imagecreatetruecolor(1800, 1200); imagefilledrectangle($i, 0, 0, 900, 1200, imagecolorallocate($i, 242, 106, 27)); imagejpeg($i, ${JSON.stringify(resolve(STATE_DIR, 'photo.jpg'))}, 85);`])
}
