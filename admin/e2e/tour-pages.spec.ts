import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, signIn } from './helpers'

const KATHMANDU = 'kathmandu-pokhara-tour-4-nights-5-days-without-air-ticket'

const lastLine = (output: string) => output.trim().split(/\r?\n/).pop()!

/**
 * docs/tour-landing-pages.md: staff make a group tour's landing page — its address, the home page promotion — and
 * publish it; the website then lists it. The tour's own content stays the package's.
 */
test('a landing page is made for a group tour, promoted and published', async ({ page }) => {
  const before = JSON.parse(lastLine(artisan('tinker', `--execute=echo json_encode(App\\Models\\TourPackage::query()->where('slug', '${KATHMANDU}')->firstOrFail()->only(['id', 'trip_type', 'status']));`)))
  artisan('tinker', `--execute=App\\Models\\TourPackage::query()->whereKey(${before.id})->update(['trip_type' => 'group_fixed', 'status' => 'published']); echo 'ok';`)
  try {
    await signIn(page, 'admin')
    await page.goto('/tour-pages')
    await page.getByRole('link', { name: 'New landing page' }).first().click()
    await page.getByLabel('Group tour', { exact: true }).selectOption(String(before.id), FIRST_LOAD)
    // The address starts as the package's own.
    await expect(page.getByLabel('Web address', { exact: true })).toHaveValue(KATHMANDU)
    await page.getByLabel('Web address', { exact: true }).fill('kathmandu-pokhara-group-tour')
    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page).toHaveURL(/\/tour-pages\/\d+$/, FIRST_LOAD)

    // Its days come from the package, ready for photos.
    await expect(page.getByTestId('tour-page-day').first()).toContainText('Day 1', FIRST_LOAD)

    // A draft is previewed by a 30-minute link: the token after the #, never in the address a server sees.
    await page.getByRole('button', { name: 'Preview', exact: true }).click()
    const links = page.getByTestId('tour-preview-links')
    await expect(links).toContainText('works until')
    await expect(links.getByRole('link', { name: /Open preview \(English\)/ })).toHaveAttribute('href', /\/en\/tours\/preview#[\w-]+\.[\w-]+$/)
    await expect(links.getByRole('link', { name: /Open preview \(Bangla\)/ })).toHaveAttribute('href', /\/tours\/preview#[\w-]+\.[\w-]+$/)
    await page.getByLabel('Show in "Upcoming group tours" on the home page').check()
    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page.getByRole('button', { name: 'Saved', exact: true }).first()).toBeVisible()
    await page.getByRole('button', { name: 'Publish', exact: true }).click()
    await expect(page.getByText('Published — the page is on the website.')).toBeVisible()

    const listed = lastLine(artisan('tinker', `--execute=echo json_encode(collect(app(App\\Http\\Controllers\\Api\\V1\\Public\\PublicContentController::class)->tourPages()->getData(true)['data'])->map(fn ($p) => [$p['slug'], $p['packageSlug'], $p['promote']]));`))
    expect(JSON.parse(listed)).toEqual([['kathmandu-pokhara-group-tour', KATHMANDU, true]])

    await page.goto('/tour-pages')
    const row = page.locator('li').filter({ hasText: '/tours/kathmandu-pokhara-group-tour' })
    await expect(row).toContainText('On home page', FIRST_LOAD)
    await expect(row).toContainText('Published')
  } finally {
    artisan('tinker', `--execute=App\\Models\\TourLandingPage::query()->delete(); App\\Models\\TourPackage::query()->whereKey(${before.id})->update(['trip_type' => '${before.trip_type}', 'status' => '${before.status}']); echo 'ok';`)
  }
})
