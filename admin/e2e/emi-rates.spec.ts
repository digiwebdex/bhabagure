import { expect, test } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/emi-preview.md: SSLCommerz's EMI chart in Site settings → EMI rates, edited by staff, and the website's booking
 * form reads the edit straight away. An empty box is a tenure the bank doesn't offer, never 0 %.
 */
test('the EMI chart is edited in Site settings and the website gets the new rates', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'EMI rates', exact: true }) })
  const table = card.getByTestId('emi-rates')
  await expect(table).toBeVisible(FIRST_LOAD)

  // The chart as supplied: Standard Chartered has no 30 months.
  await expect(card.getByLabel('Standard Chartered, 30 months, charge in percent')).toHaveValue('')
  await expect(card.getByLabel('Standard Chartered, 36 months, charge in percent')).toHaveValue('31.07')
  await expect(card.getByLabel('The City Bank, 12 months, charge in percent')).toHaveValue('11.85')
  await expect(card.getByLabel('Other banks (35), 3 months, charge in percent')).toHaveValue('5.13')

  // The City Bank's 12 months becomes 12.5 %, and it stops offering 3 months.
  await card.getByLabel('The City Bank, 12 months, charge in percent').fill('12.5')
  await card.getByLabel('The City Bank, 3 months, charge in percent').fill('')
  await card.getByRole('button', { name: 'Save' }).click()
  await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()

  await page.reload()
  await expect(card.getByLabel('The City Bank, 12 months, charge in percent')).toHaveValue('12.5', FIRST_LOAD)
  await expect(card.getByLabel('The City Bank, 3 months, charge in percent')).toHaveValue('')

  const pricing = await page.request.get(`${API_URL}/api/v1/public/pricing`, { headers: { Accept: 'application/json' } })
  const emi = ((await pricing.json()) as { data: { emi: { groups: { key: string; tenures: { months: number; percent: number }[] }[] } } }).data.emi
  const city = emi.groups.find((group) => group.key === 'city_bank')!
  expect(city.tenures.find((tenure) => tenure.months === 12)?.percent).toBe(12.5)
  expect(city.tenures.some((tenure) => tenure.months === 3)).toBe(false)
  expect(emi.groups.find((group) => group.key === 'standard_chartered')!.tenures.map((tenure) => tenure.months)).toEqual([3, 6, 9, 12, 18, 24, 36])

  // Put the chart back for the other tests.
  await card.getByLabel('The City Bank, 12 months, charge in percent').fill('11.85')
  await card.getByLabel('The City Bank, 3 months, charge in percent').fill('5.65')
  await card.getByRole('button', { name: 'Save' }).click()
  await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
})
