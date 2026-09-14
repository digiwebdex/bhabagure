import { expect, test } from '@playwright/test'

import { FIRST_LOAD, signIn } from './helpers'

const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights'

/** docs/phase-5-admin-core.md §4.3: "+ New booking" — the website's price, the office's customer, owned by its creator. */
test('a sales agent books a walk-in customer at the website price; the booking is theirs and a known number is refused', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await page.getByRole('link', { name: '+ New booking' }).click()
  await expect(page.getByRole('heading', { name: 'New booking', level: 1 })).toBeVisible()

  await page.getByLabel('Full name').first().fill('Walk In Customer')
  await page.getByLabel('Phone', { exact: true }).fill('01711-424242')
  await page.getByLabel('How they reached us').selectOption('walk_in')
  await page.getByLabel('Full name').nth(1).fill('Walk In Customer')
  await page.getByRole('button', { name: 'One traveller more' }).click()
  await page.getByLabel('Full name').nth(2).fill('Second Traveller')

  await page.getByLabel('Package', { exact: true }).selectOption(MUSTANG)
  const travel = new Date(Date.now() + 45 * 86_400_000).toISOString().slice(0, 10)
  const dateField = page.getByLabel('Travel date', { exact: true })
  if (await dateField.count()) await dateField.fill(travel)
  else await page.getByLabel('Departure', { exact: true }).selectOption({ index: 1 })

  await expect(page.getByTestId('new-booking-total')).toHaveText('৳ 1,53,000')
  await page.getByRole('button', { name: 'Create booking · ৳ 1,53,000' }).click()

  await expect(page).toHaveURL(/\/bookings\/\d+$/)
  const reference = (await page.getByRole('heading', { level: 1 }).textContent())!.match(/BH-\d{4}-\d{3,}/)?.[0]
  expect(reference).toBeTruthy()

  await page.goto('/bookings?owner=mine')
  const row = page.getByTestId('bookings-table').locator('tbody tr').filter({ hasText: reference! })
  await expect(row).toContainText('Walk In Customer', FIRST_LOAD)
  await expect(row.getByText('Pool', { exact: true })).toHaveCount(0)
  await expect(row.getByRole('link', { name: `WhatsApp — ${reference}` })).toHaveAttribute('href', 'https://wa.me/8801711424242')

  // The same number again as a "new" customer: refused, pointing at the existing record.
  await page.goto('/bookings/new')
  await page.getByLabel('Full name').first().fill('Someone Else')
  await page.getByLabel('Phone', { exact: true }).fill('01711424242')
  await page.getByLabel('Full name').nth(1).fill('Someone Else')
  await page.getByLabel('Package', { exact: true }).selectOption(MUSTANG)
  if (await page.getByLabel('Travel date', { exact: true }).count()) await page.getByLabel('Travel date', { exact: true }).fill(travel)
  else await page.getByLabel('Departure', { exact: true }).selectOption({ index: 1 })
  await page.getByRole('button', { name: /^Create booking/ }).click()
  await expect(page.getByRole('alert')).toContainText('already exists')
  await expect(page).toHaveURL(/\/bookings\/new$/)
})
