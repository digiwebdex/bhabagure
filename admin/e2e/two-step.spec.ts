import { expect, test, type Page } from '@playwright/test'
import { createHmac } from 'node:crypto'

import { API_URL, FIRST_LOAD, password, signIn } from './helpers'

/**
 * docs/two-step-sign-in.md: a staff member turns on two-step sign-in in their profile with an authenticator app, signs
 * in again with a code (or a recovery code), and turns it off. The codes are made here as the app would make them.
 * The account is the tour operator's, put back as it was at the end (and by the super admin, should a step fail).
 */
const ROLE = 'tour_operator'
const EMAIL = `${ROLE}@e2e.test`

/** RFC 6238, as an authenticator app: HMAC-SHA1 over the 30-second step, 6 digits. */
function totp(base32: string, at = Date.now()): { code: string; step: number } {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bits = base32
    .replace(/\s+/g, '')
    .toUpperCase()
    .split('')
    .map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0'))
    .join('')
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)))
  const step = Math.floor(at / 1000 / 30)
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(step))
  const hash = createHmac('sha1', key).update(counter).digest()
  const offset = hash[19] & 0x0f
  const value = ((hash[offset] & 0x7f) << 24) | (hash[offset + 1] << 16) | (hash[offset + 2] << 8) | hash[offset + 3]
  return { code: String(value % 1_000_000).padStart(6, '0'), step }
}

/** A code from a step not used yet: the API takes each step once. Waits for the next one when it must. */
async function freshCode(page: Page, secret: string, used: Set<number>): Promise<string> {
  for (;;) {
    const { code, step } = totp(secret)
    if (!used.has(step)) {
      used.add(step)
      return code
    }
    await page.waitForTimeout(1000)
  }
}

async function passwordStep(page: Page) {
  await page.context().clearCookies()
  await page.goto('/login')
  await page.getByLabel('Email').fill(EMAIL)
  await page.getByLabel('Password').fill(password())
  await page.getByRole('button', { name: 'Sign in' }).click()
}

test.afterAll(async ({ request }) => {
  // Whatever happened above, the tour operator signs in with the password alone again for the other files.
  const login = await request.post(`${API_URL}/api/v1/staff/auth/login`, { data: { email: 'super_admin@e2e.test', password: password() } })
  const token = (await login.json()).access_token as string
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
  const list = await request.get(`${API_URL}/api/v1/admin/staff?search=${encodeURIComponent(EMAIL)}`, { headers })
  const id = (await list.json()).data?.[0]?.id as number | undefined
  if (id) await request.post(`${API_URL}/api/v1/admin/staff/${id}/two-factor/reset`, { headers })
})

test('two-step sign-in is set up in the profile, asked for at sign-in, a recovery code works, and it is turned off', async ({ page }) => {
  test.setTimeout(180_000)
  const used = new Set<number>()

  await signIn(page, ROLE)
  await page.goto('/profile')
  const card = page.locator('section, div').filter({ has: page.getByRole('heading', { name: 'Two-step sign-in' }) }).last()
  await expect(page.getByTestId('two-step-off')).toBeVisible(FIRST_LOAD)
  await page.getByRole('button', { name: 'Set up' }).click()
  await page.getByRole('button', { name: 'Show the QR code' }).click()
  await expect(page.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible(FIRST_LOAD)
  const secret = (await page.getByTestId('two-step-key').innerText()).replace(/\s+/g, '')
  expect(secret).toMatch(/^[A-Z2-7]{32}$/)

  // A wrong code is refused; the app's code turns it on and shows the recovery codes once.
  await page.getByLabel('Code').fill('000000')
  await page.getByRole('button', { name: 'Turn on' }).click()
  await expect(page.getByTestId('two-step-scan')).toContainText('isn’t right', FIRST_LOAD)
  await page.getByLabel('Code').fill(await freshCode(page, secret, used))
  await page.getByRole('button', { name: 'Turn on' }).click()
  const codes = page.getByTestId('recovery-codes').getByRole('listitem')
  await expect(codes).toHaveCount(10, FIRST_LOAD)
  const recovery = await codes.first().innerText()
  await expect(page.getByRole('button', { name: 'Done' })).toBeDisabled()
  await page.getByLabel('I’ve saved my recovery codes').check()
  await page.getByRole('button', { name: 'Done' }).click()
  await expect(page.getByTestId('two-step-on')).toContainText('10 recovery codes left.')
  await expect(card).toContainText('On')

  // Signing in again: the password, then the code. A wrong code is refused, the app's opens the admin.
  await passwordStep(page)
  await expect(page.getByTestId('two-step-sign-in')).toBeVisible(FIRST_LOAD)
  await page.getByLabel('Code').fill('123456')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByTestId('two-step-sign-in')).toContainText('isn’t right', FIRST_LOAD)
  await page.getByLabel('Code').fill(await freshCode(page, secret, used))
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 })

  // A recovery code instead of the app: it works once, and says how many are left.
  await passwordStep(page)
  await page.getByLabel('Code').fill(recovery.toUpperCase())
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByText('Signed in with a recovery code: 9 left.')).toBeVisible(FIRST_LOAD)

  // Turning it off takes the password and a code.
  await page.goto('/profile')
  await page.getByRole('button', { name: 'Turn off' }).click()
  await page.getByLabel('Password').fill(password())
  await page.getByLabel('Code').fill(await freshCode(page, secret, used))
  await page.getByRole('button', { name: 'Turn off' }).click()
  await expect(page.getByText('Two-step sign-in is off.')).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('two-step-off')).toBeVisible()

  // And the password alone signs in again.
  await signIn(page, ROLE)
})

test('only the super admin sees Security in site settings, with how many have two-step sign-in on', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  await expect(page.getByRole('heading', { name: 'Contact' })).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('security-card')).toHaveCount(0)

  await signIn(page, 'super_admin')
  await page.goto('/settings')
  const security = page.getByTestId('security-card')
  await expect(security).toBeVisible(FIRST_LOAD)
  await expect(security).toContainText('Require two-step sign-in for everyone')
  await expect(security).toContainText(/\d+ of \d+ people with a login have it on\./)
})
