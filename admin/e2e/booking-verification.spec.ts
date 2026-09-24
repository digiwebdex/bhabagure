import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { API_DIR, artisan } from '../../scripts/e2e-api.mjs'
import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/booking-phone-verification.md: Admin → Site settings → Website booking switches the code to the customer's mobile
 * on and off, beside whether codes reach customers; a booking saved with the code shows "Verified by code".
 */

/** The newest code the SMS stand-in "sent" to this number (0171XXXXXXX). */
function lastCode(phone: string): string {
  const entries = readFileSync(resolve(API_DIR, 'storage/logs/sms-fake.log'), 'utf8')
    .split(/\r?\n(?=\[\d{4}-\d{2}-\d{2})/)
    .filter((entry) => entry.includes(`SMS (fake) to 88${phone}`))
  const code = /(?<!\d)(\d{6})(?!\d)/.exec(entries.at(-1) ?? '')?.[1]
  if (!code) throw new Error(`No code was sent to ${phone}`)
  return code
}

test('the check is switched in Site settings, and a booking saved with the code shows the mobile verified', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Website booking' }) })
  // No code has gone out on this database yet: the warning to leave it off.
  await expect(card.getByTestId('code-delivery')).toContainText('No code has reached a customer yet', FIRST_LOAD)
  const toggle = card.getByRole('switch')
  await expect(toggle).toHaveAttribute('aria-checked', 'false')

  try {
    await toggle.click()
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()

    // A customer books on the website with the code sent to their mobile.
    const phone = `0171${String(Date.now()).slice(-7)}`
    const headers = { Accept: 'application/json' }
    expect((await page.request.post(`${API_URL}/api/v1/public/booking-codes`, { headers, data: { phone, email: 'verified@example.test', locale: 'en' } })).status()).toBe(202)
    const booking = {
      package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights',
      travel_date: new Date(Date.now() + 70 * 86_400_000).toISOString().slice(0, 10),
      pax: 2, room: 'twin', addons: [], travellers: [{ name: 'Verified Customer', phone, email: 'verified@example.test' }, {}],
      expected_total: 153000, terms_accepted: true, locale: 'en',
    }
    expect((await page.request.post(`${API_URL}/api/v1/public/bookings`, { headers, data: booking })).status()).toBe(422)
    const created = await page.request.post(`${API_URL}/api/v1/public/bookings`, { headers, data: { ...booking, verification_code: lastCode(phone) } })
    expect(created.status()).toBe(201)
    const reference = ((await created.json()) as { data: { reference: string } }).data.reference

    // The code reached the customer: the note beside the switch says so.
    await page.reload()
    await expect(card.getByTestId('code-delivery')).toContainText('Codes are reaching customers', FIRST_LOAD)
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    // Switched off again the same way.
    await toggle.click()
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
    await page.reload()
    await expect(toggle).toHaveAttribute('aria-checked', 'false', FIRST_LOAD)

    await page.goto('/bookings')
    await page.getByRole('link', { name: reference, exact: true }).click()
    await expect(page.getByTestId('phone-verified')).toContainText('Verified by code', FIRST_LOAD)
  } finally {
    // Whatever happened above: later tests book on the website without a code.
    artisan('tinker', `--execute=App\\Models\\SiteSetting::query()->updateOrCreate(['key' => 'booking'], ['value' => ['verifyPhone' => false]]); echo 'ok';`)
  }
})
