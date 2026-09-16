import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, signIn } from './helpers'

const NATURE = 'nature-cultural-scenic-tour-5-nights-6-days-kathmandu-pokhara-nagarkot-without-air-ticket'

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.D: a package priced by hotel category × travellers. Staff fill the grid in
 * the package editor; the office books in a category at the grid price, and the booking keeps the category.
 */
test('the editor prices a package by hotel category, and a new booking in 4-star is priced from that row', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/packages')
  await page.getByRole('link', { name: /Nature & Cultural Scenic Tour/ }).click()
  const grid = page.getByTestId('price-grid')
  await expect(grid).toBeVisible(FIRST_LOAD)

  try {
    for (const [label, price] of [
      ['Basic / 3-star, 1 traveller', '21000'],
      ['Basic / 3-star, 2 travellers', '18000'],
      ['4-star, 1 traveller', '30000'],
      ['4-star, 2 travellers', '25000'],
      ['4-star, 4 travellers', '23000'],
    ] as const) {
      await grid.getByLabel(label, { exact: true }).fill(price)
    }
    // A row without the 1-traveller price isn't sold.
    await grid.getByLabel('5-star, 2 travellers', { exact: true }).fill('40000')
    await expect(page.getByTestId('price-grid-summary')).toHaveText('Sold in: Basic / 3-star, 4-star · cards show BDT 18,000 per person')
    await expect(page.getByLabel('Regular price', { exact: false })).toBeDisabled()
    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page.getByText('5-star: Enter the price for 1 traveller: every other group size falls back to it.')).toBeVisible()
    await grid.getByLabel('5-star, 2 travellers', { exact: true }).fill('')
    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page.getByRole('button', { name: 'Saved', exact: true }).first()).toBeVisible()

    // The office books three travellers in 4-star: the 2-traveller price, 25,000 × 3 = 75,000 + 2% = 76,500.
    await page.goto('/bookings/new')
    await page.getByLabel('Full name').first().fill('Grid Customer')
    await page.getByLabel('Phone', { exact: true }).fill(`0171${String(Date.now()).slice(-7)}`)
    await page.getByLabel('Travellers', { exact: true }).fill('3')
    await page.getByLabel('Package', { exact: true }).selectOption(NATURE)
    const travel = new Date(Date.now() + 45 * 86_400_000).toISOString().slice(0, 10)
    if (await page.getByLabel('Travel date', { exact: true }).count()) await page.getByLabel('Travel date', { exact: true }).fill(travel)
    else await page.getByLabel('Departure', { exact: true }).selectOption({ index: 1 })

    const category = page.getByLabel('Hotel category', { exact: true })
    await expect(category).toHaveValue('3')
    await expect(page.getByTestId('new-booking-total')).toHaveText('BDT 55,080')
    await category.selectOption('4')
    await expect(page.getByTestId('new-booking-total')).toHaveText('BDT 76,500')
    await page.getByRole('button', { name: 'Create booking · BDT 76,500' }).click()
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/BH-\d{4}-\d{3,}/, FIRST_LOAD)
    await expect(page.locator('main')).toContainText('BDT 76,500', FIRST_LOAD)
    await expect(page.getByTestId('booking-hotel-category')).toHaveText('Hotel category: 4-star')

    const stored = artisan('tinker', `--execute=echo json_encode(App\\Models\\Booking::query()->latest('id')->first(['hotel_category', 'unit_price']));`).trim().split(/\r?\n/).pop()!
    expect(JSON.parse(stored)).toEqual({ hotel_category: '4', unit_price: '25000.00' })
  } finally {
    // Other specs price this package the old way.
    artisan('tinker', `--execute=App\\Models\\TourPackage::query()->where('slug', '${NATURE}')->update(['price_grid' => null, 'regular_price' => 18000]); echo 'ok';`)
  }
})
