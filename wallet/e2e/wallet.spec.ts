import { expect, test, type Page } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §8, §10: the super admin enrolls an authenticator and opens the wallet;
 * cash in with a saved reference; a deal with an advance and a payment; a reversal with its reason. An admin can't get in.
 */
test.describe.configure({ mode: 'serial' })

const password = () => (JSON.parse(readFileSync(resolve(import.meta.dirname, '.state/staff.json'), 'utf8')) as { password: string }).password

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

/** RFC 6238, as the authenticator app computes it, from the key the enrollment screen shows. */
function totp(secret: string, offsetSteps = 0): string {
  const bits = secret.replace(/\s+/g, '').split('').map((char) => ALPHABET.indexOf(char).toString(2).padStart(5, '0')).join('')
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000) + offsetSteps))
  const hash = createHmac('sha1', key).update(counter).digest()
  const offset = hash[19] & 0x0f
  return String((hash.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

let secret = ''

async function signIn(page: Page, email: string) {
  await page.goto('/')
  await page.evaluate(() => localStorage.setItem('bh-lang', 'en'))
  await page.reload()
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password())
  await page.getByRole('button', { name: 'Continue' }).click()
}

test('the super admin sets up an authenticator app, opens the wallet, and an admin is refused', async ({ page }) => {
  await signIn(page, 'admin@e2e.test')
  await expect(page.getByRole('alert')).toContainText('That email and password don’t open the wallet.', { timeout: 15_000 })

  await signIn(page, 'owner@e2e.test')
  await expect(page.getByRole('heading', { name: 'Set up your authenticator app' })).toBeVisible({ timeout: 15_000 })
  await expect(page.getByRole('img', { name: 'QR code for the authenticator app' })).toBeVisible()
  secret = (await page.getByTestId('enroll-secret').textContent()) ?? ''
  expect(secret.replace(/\s+/g, '')).toMatch(/^[A-Z2-7]{32}$/)

  await page.getByLabel('6-digit code').fill('000000')
  await page.getByRole('button', { name: 'Open the wallet' }).click()
  await expect(page.getByRole('alert')).toContainText('That code isn’t right')
  await page.getByLabel('6-digit code').fill(totp(secret))
  await page.getByRole('button', { name: 'Open the wallet' }).click()
  await expect(page.getByTestId('wallet-balance')).toHaveText('৳ 0', { timeout: 15_000 })
  await expect(page.getByText('Private · separate from company books')).toBeVisible()
})

test('cash in with a saved reference, a deal with its advance and a payment, and a reversal with its reason', async ({ page }) => {
  await signIn(page, 'owner@e2e.test')
  await expect(page.getByRole('heading', { name: 'Authenticator code' })).toBeVisible({ timeout: 15_000 })
  // The previous test used the current step; the next one is still accepted (one step of drift either way).
  await page.getByLabel('6-digit code').fill(totp(secret, 1))
  await page.getByRole('button', { name: 'Open the wallet' }).click()
  await expect(page.getByTestId('wallet-balance')).toBeVisible({ timeout: 15_000 })

  // A business of the proprietor's, added in the wallet itself; a saved reference clicked into the form.
  const form = page.getByTestId('cash-form')
  await page.getByText('Add a business or source').click()
  await page.getByLabel('Business or source name').fill('Family business')
  await page.getByRole('button', { name: '+ Add' }).click()
  await expect(form.getByLabel('Which business or source')).toContainText('Family business')
  await form.getByLabel('Which business or source').selectOption({ label: 'Family business' })
  await form.getByLabel('Save a new reference').fill('Profit share')
  await form.getByRole('button', { name: '+ Save' }).click()
  await expect(form.getByLabel('Reference · what this money is for')).toHaveValue('Profit share')
  await form.getByLabel('Amount (৳)').fill('120000')
  await form.getByRole('button', { name: 'Add cash in' }).click()
  await expect(page.getByText('Cash in ৳ 1,20,000 recorded')).toBeVisible()
  await expect(page.getByTestId('wallet-balance')).toHaveText('৳ 1,20,000')
  await expect(page.getByTestId('breakdown')).toContainText('Family business')

  // A deal: the advance can't exceed the total; it goes into the balance, and a payment settles what is due.
  const deal = page.getByTestId('deal-form')
  await deal.getByLabel('Company or client').fill('Software project')
  await deal.getByLabel('Deal total').fill('80000')
  await deal.getByLabel('Advance').fill('90000')
  await expect(deal).toContainText('The advance can’t be more than the deal total.')
  await deal.getByLabel('Advance').fill('40000')
  await expect(deal).toContainText('Advance ৳ 40,000 will be added to your balance · ৳ 40,000 stays due')
  await deal.getByRole('button', { name: 'Save deal & advance' }).click()
  await expect(page.getByText('Deal saved · advance ৳ 40,000 added to balance')).toBeVisible()
  await expect(page.getByTestId('due-total')).toHaveText('Total due ৳ 40,000')
  await expect(page.getByTestId('wallet-balance')).toHaveText('৳ 1,60,000')
  await page.getByTestId('deals').getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByTestId('deals')).toContainText('Fully paid')
  await expect(page.getByTestId('wallet-balance')).toHaveText('৳ 2,00,000')

  // Reverse the cash in: an entry the other way with the reason; nothing is deleted.
  const history = page.getByTestId('history')
  await history.getByRole('button', { name: 'Reverse “Profit share”' }).click()
  const dialog = page.getByRole('dialog', { name: 'Reverse ৳ 1,20,000' })
  await dialog.getByLabel('Reason').fill('Entered in the wrong month')
  await dialog.getByRole('button', { name: 'Reverse' }).click()
  await expect(history).toContainText('Reversal · Entered in the wrong month')
  await expect(page.getByTestId('wallet-balance')).toHaveText('৳ 80,000')
  await expect(history.getByText('Reversed', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page.getByRole('heading', { name: 'Open the wallet' })).toBeVisible()
})
