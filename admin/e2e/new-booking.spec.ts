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
  // Two travellers: the office books a count, and names are completed on the booking itself.
  await page.getByLabel('Travellers', { exact: true }).fill('2')

  await page.getByLabel('Package', { exact: true }).selectOption(MUSTANG)
  const travel = new Date(Date.now() + 45 * 86_400_000).toISOString().slice(0, 10)
  const dateField = page.getByLabel('Travel date', { exact: true })
  if (await dateField.count()) await dateField.fill(travel)
  else await page.getByLabel('Departure', { exact: true }).selectOption({ index: 1 })

  await expect(page.getByTestId('new-booking-total')).toHaveText('BDT 1,53,000')
  await page.getByRole('button', { name: 'Create booking · BDT 1,53,000' }).click()

  await expect(page).toHaveURL(/\/bookings\/\d+$/)
  // The URL changes before the booking loads; until then the heading is still "New booking".
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toContainText(/BH-\d{4}-\d{3,}/, FIRST_LOAD)
  const reference = (await heading.textContent())!.match(/BH-\d{4}-\d{3,}/)?.[0]
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
  await page.getByLabel('Package', { exact: true }).selectOption(MUSTANG)
  if (await page.getByLabel('Travel date', { exact: true }).count()) await page.getByLabel('Travel date', { exact: true }).fill(travel)
  else await page.getByLabel('Departure', { exact: true }).selectOption({ index: 1 })
  await page.getByRole('button', { name: /^Create booking/ }).click()
  await expect(page.getByRole('alert')).toContainText('already exists')
  await expect(page).toHaveURL(/\/bookings\/new$/)
})

/**
 * docs/custom-service-bookings.md (asked for 2026-09-19): beside the fixed packages, a custom service — its name and
 * items, each at a price per person, the service charge and VAT on top, and a date only if it is known.
 */
test('the office books a custom service with its own items and prices, and the draft invoice keeps them', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/bookings/new')
  await expect(page.getByRole('heading', { name: 'New booking', level: 1 })).toBeVisible(FIRST_LOAD)

  await page.getByLabel('Full name').first().fill('Custom Service Customer')
  await page.getByLabel('Phone', { exact: true }).fill('01711-515151')
  await page.getByLabel('Travellers', { exact: true }).fill('2')
  await page.getByLabel('Package', { exact: true }).selectOption({ label: '★ Custom service — your own items and prices' })

  // No room, hotel category or add-ons: those belong to packages.
  await expect(page.getByLabel('Room', { exact: true })).toHaveCount(0)
  await page.getByLabel('Service name').fill("Cox's Bazar family trip")
  await page.getByRole('textbox', { name: 'Item 1' }).fill('Hotel, 3 nights')
  await page.getByLabel('Price per person (BDT)').first().fill('8000')
  await page.getByRole('button', { name: '+ Add item' }).click()
  await page.getByRole('textbox', { name: 'Item 2' }).fill('Air ticket')
  await page.getByLabel('Price per person (BDT)').nth(1).fill('6500')

  // 2 × (8,000 + 6,500) = 29,000 + 2% = 29,580. No travel date: it is optional for a custom service.
  await expect(page.getByTestId('new-booking-total')).toHaveText('BDT 29,580')
  await page.getByRole('button', { name: 'Create booking · BDT 29,580' }).click()
  await expect(page).toHaveURL(/\/bookings\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/BH-\d{4}-\d{3,}/, FIRST_LOAD)

  // The draft invoice lists the items by name; a third traveller re-prices from them: 3 × 14,500 = 43,500 + 870 = 44,370.
  const quote = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Quote' }) })
  await expect(quote).toContainText('Hotel, 3 nights')
  await expect(quote).toContainText('Air ticket')
  await quote.getByRole('button', { name: 'One traveller more' }).click()
  await expect(quote).toContainText('BDT 44,370')
  await quote.getByRole('button', { name: 'Save quote' }).click()
  await expect(page.getByText('Quote saved')).toBeVisible()
  await page.reload()
  await expect(quote).toContainText('BDT 44,370', FIRST_LOAD)
  await expect(quote).toContainText('Hotel, 3 nights')
})
