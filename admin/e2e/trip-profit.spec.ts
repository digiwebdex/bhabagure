import { readFileSync } from 'node:fs'

import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, signIn } from './helpers'

/**
 * docs/trip-profit.md: an admin logs a trip's costs and what was collected; the form works the total and profit out as
 * they type, the list shows the breakdown and a profit in green (a loss in red), the totals follow the month, and the
 * CSV is the same list. Other roles don't see it.
 */
test('a trip is added, priced as typed, shown in the month, downloaded, made a loss and deleted', async ({ page }) => {
  artisan('tinker', `--execute=App\\Models\\TripProfit::withTrashed()->forceDelete(); echo 'ok';`)
  try {
    await signIn(page, 'admin')
    await page.goto('/trip-profit')
    await expect(page.getByRole('heading', { name: 'Trip profit' })).toBeVisible(FIRST_LOAD)
    await page.getByLabel('Period', { exact: true }).selectOption('2026-10').catch(() => undefined)

    await page.getByRole('button', { name: '+ Add trip' }).click()
    const form = page.getByTestId('trip-form')
    await form.getByLabel('Trip title / package name').fill('E2E Mustang Valley Trip')
    await form.getByLabel('Trip type (optional)').selectOption('group_tour')
    await form.getByLabel('Trip date').fill('2026-10-19')
    await form.getByLabel('Travellers (pax)').fill('18')
    await form.getByLabel('Flight cost').fill('612000.50')
    await form.getByLabel('Land package cost').fill('540000.25')
    // Worked out as it is typed.
    await expect(form.getByTestId('trip-preview-cost')).toHaveText('BDT 11,52,000.75')
    await form.getByLabel('Other cost').fill('36000.10')
    await form.getByLabel('Total collected').fill('1404000')
    await expect(form.getByTestId('trip-preview-cost')).toHaveText('BDT 11,88,000.85')
    await expect(form.getByTestId('trip-preview-profit')).toHaveText('BDT 2,15,999.15')
    await expect(form.getByTestId('trip-preview-profit')).toHaveClass(/text-green/)
    await form.getByRole('button', { name: 'Save', exact: true }).click()
    // The e2e API answers one request at a time; under load a save can take several seconds.
    await expect(form).toBeHidden(FIRST_LOAD)

    await page.getByLabel('Period', { exact: true }).selectOption('2026-10')
    const row = page.getByTestId('trip-row').filter({ hasText: 'E2E Mustang Valley Trip' })
    await expect(row).toContainText('19 October 2026', FIRST_LOAD)
    await expect(row.getByTestId('trip-profit')).toHaveText('BDT 2,15,999.15')
    await expect(row.getByTestId('trip-profit')).toHaveClass(/text-green/)
    await row.getByTestId('trip-cost').click()
    await expect(row.getByTestId('trip-breakdown')).toContainText('Flight costBDT 6,12,000.50')
    await expect(page.getByTestId('trip-summary')).toContainText('BDT 2,15,999.15')

    // The CSV: the same month, with a totals row.
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('trip-export').click()])
    expect(download.suggestedFilename()).toBe('bhabaghure-trip-profit-2026-10.csv')
    const csv = readFileSync((await download.path())!, 'utf8')
    expect(csv).toContain('"E2E Mustang Valley Trip","group tour",2026-10-19,18,612000.50,540000.25,36000.10,1188000.85,1404000.00,215999.15')
    expect(csv).toContain('Total,,,18,')

    // A loss shows red.
    await row.getByRole('button', { name: 'Edit E2E Mustang Valley Trip' }).click()
    await form.getByLabel('Total collected').fill('1000000')
    await expect(form.getByTestId('trip-preview-profit')).toHaveClass(/text-red/)
    await form.getByRole('button', { name: 'Save', exact: true }).click()
    // The e2e API answers one request at a time; under load a save can take several seconds.
    await expect(form).toBeHidden(FIRST_LOAD)
    await expect(row.getByTestId('trip-profit')).toHaveText('−BDT 1,88,000.85')
    await expect(row.getByTestId('trip-profit')).toHaveClass(/text-red/)

    await row.getByRole('button', { name: 'Delete E2E Mustang Valley Trip' }).click()
    await page.getByRole('button', { name: 'Yes, continue', exact: true }).click()
    await expect(page.getByText('No trips for this period')).toBeVisible(FIRST_LOAD)
  } finally {
    artisan('tinker', `--execute=App\\Models\\TripProfit::withTrashed()->forceDelete(); echo 'ok';`)
  }
})

test('staff without trip profit neither see it in the menu nor open it', async ({ page }) => {
  await signIn(page, 'accountant')
  await expect(page.getByRole('link', { name: 'Trip profit' })).toHaveCount(0)
  await page.goto('/trip-profit')
  await expect(page.getByRole('heading', { name: 'Trip profit' })).toHaveCount(0)
})
