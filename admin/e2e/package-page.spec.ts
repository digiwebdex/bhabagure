import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, signIn } from './helpers'

const KATHMANDU = 'kathmandu-pokhara-tour-4-nights-5-days-without-air-ticket'

/**
 * docs/package-page.md: staff write a package's overview for its page and brochure, and make a similar tour by
 * duplicating one — a new draft with the text, itinerary and prices, without the photos or dates.
 */
test('staff write an overview and duplicate a package into a new draft', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/packages')
  await page.getByRole('link', { name: /Kathmandu–Pokhara Tour/ }).click()
  const overview = page.getByLabel('Overview (English)', { exact: true })
  await expect(overview).toBeVisible(FIRST_LOAD)

  try {
    await overview.fill('Five days between Kathmandu and the lakes of Pokhara.\n\nSunrise at Sarangkot.')
    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page.getByRole('button', { name: 'Saved', exact: true }).first()).toBeVisible()

    await page.getByRole('button', { name: 'Duplicate', exact: true }).click()
    await page.getByRole('button', { name: 'Yes, continue', exact: true }).click()
    await expect(page.getByText('Copy made — this is the new draft.')).toBeVisible()
    await expect(page).toHaveURL(/\/packages\/\d+$/)

    // The copy: a draft with its own code and address, the overview and the itinerary; no photos yet.
    // The first: each itinerary day has its own "Title (English)".
    await expect(page.getByLabel('Title (English)', { exact: true }).first()).toHaveValue(/Kathmandu–Pokhara Tour.* \(copy\)$/, FIRST_LOAD)
    await expect(page.getByLabel('Web address (slug)', { exact: true })).toHaveValue(`${KATHMANDU}-copy`)
    await expect(page.getByLabel('Overview (English)', { exact: true })).toHaveValue('Five days between Kathmandu and the lakes of Pokhara.\n\nSunrise at Sarangkot.')
    const stored = artisan('tinker', `--execute=$p = App\\Models\\TourPackage::query()->where('slug', '${KATHMANDU}-copy')->firstOrFail(); echo json_encode(['status' => $p->status->value, 'images' => $p->images()->count(), 'days' => $p->itineraryDays()->count()]);`).trim().split(/\r?\n/).pop()!
    expect(JSON.parse(stored)).toMatchObject({ status: 'draft', images: 0 })
    expect(JSON.parse(stored).days).toBeGreaterThan(0)
  } finally {
    artisan('tinker', `--execute=App\\Models\\TourPackage::query()->where('slug', 'like', '${KATHMANDU}-copy%')->get()->each->delete(); App\\Models\\TourPackage::query()->where('slug', '${KATHMANDU}')->update(['overview_en' => null]); echo 'ok';`)
  }
})
