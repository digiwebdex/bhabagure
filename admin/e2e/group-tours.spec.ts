import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, signIn } from './helpers'

const KATHMANDU = 'kathmandu-pokhara-tour-4-nights-5-days-without-air-ticket'

/**
 * docs/fixed-departure-group-tours.md: staff mark a package as a group tour with its own single and triple prices; the
 * office then books it on one of its departures only, and the room changes the price.
 */
test('a package marked as a group tour is booked at the office on its departures, at its own single price', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/packages')
  await page.getByRole('link', { name: /Kathmandu–Pokhara Tour/ }).click()
  const tripType = page.getByLabel('Trip type', { exact: true })
  await expect(tripType).toHaveValue('customized', FIRST_LOAD)

  try {
    // 13,800 per person in triple sharing (the base): twin +5% = 14,490; single +50% = 20,700 (docs/room-rates.md).
    await tripType.selectOption('group_fixed')
    await expect(page.getByTestId('price-grid')).toHaveCount(0)
    const rooms = page.getByTestId('room-rates')
    await rooms.getByLabel('Twin sharing (+%)').fill('5')
    await expect(rooms).toContainText('BDT 14,490 per person')
    await rooms.getByLabel('Single room (+%)').fill('50')
    await expect(rooms).toContainText('BDT 20,700 per person')
    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page.getByRole('button', { name: 'Saved', exact: true }).first()).toBeVisible()

    // No departures yet: the office can't pick a date.
    await page.goto('/bookings/new')
    await page.getByLabel('Package', { exact: true }).selectOption(KATHMANDU, FIRST_LOAD)
    await expect(page.getByTestId('no-departures')).toBeVisible()
    await expect(page.getByLabel('Travel date', { exact: true })).toHaveCount(0)

    const departsOn = new Date(Date.now() + 50 * 86_400_000).toISOString().slice(0, 10)
    artisan('tinker', `--execute=App\\Models\\TourPackage::query()->where('slug', '${KATHMANDU}')->firstOrFail()->departures()->create(['departs_on' => '${departsOn}', 'seats_total' => 10, 'status' => 'scheduled']); echo 'ok';`)
    await page.reload()
    await page.getByLabel('Full name').first().fill('Group Tour Customer')
    await page.getByLabel('Phone', { exact: true }).fill(`0171${String(Date.now()).slice(-7)}`)
    await page.getByLabel('Travellers', { exact: true }).fill('2')
    await page.getByLabel('Package', { exact: true }).selectOption(KATHMANDU, FIRST_LOAD)
    await page.getByLabel('Departure', { exact: true }).selectOption(departsOn)
    await expect(page.getByLabel('Hotel category', { exact: true })).toHaveCount(0)
    // Two in singles: 13,800 × 2 + 6,900 × 2 = 41,400 + 2% = 42,228.
    await page.getByLabel('Room', { exact: true }).selectOption({ label: 'Single room · BDT 20,700' })
    await expect(page.getByTestId('new-booking-total')).toHaveText('BDT 42,228')
    await page.getByRole('button', { name: 'Create booking · BDT 42,228' }).click()
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/BH-\d{4}-\d{3,}/, FIRST_LOAD)
    await expect(page.locator('main')).toContainText('BDT 42,228', FIRST_LOAD)

    const stored = artisan('tinker', `--execute=echo json_encode(App\\Models\\Booking::query()->latest('id')->first(['travel_start', 'single_supplement_amount', 'room_rates', 'fixed_price']));`).trim().split(/\r?\n/).pop()!
    expect(JSON.parse(stored)).toMatchObject({ travel_start: `${departsOn}T00:00:00.000000Z`, single_supplement_amount: '13800.00', room_rates: { singleSupplementPercent: 50, twinSupplementPercent: 5 }, fixed_price: true })

    // The package list marks it.
    await page.goto('/packages')
    await expect(page.locator('li, tr').filter({ hasText: 'Kathmandu–Pokhara Tour' }).first()).toContainText('Group tour · fixed departure', FIRST_LOAD)
  } finally {
    // Other specs book this package on any date.
    artisan('tinker', `--execute=$p = App\\Models\\TourPackage::query()->where('slug', '${KATHMANDU}')->firstOrFail(); $p->update(['trip_type' => 'customized', 'single_supplement_percent' => 15, 'twin_supplement_percent' => 0]); $p->departures()->update(['status' => 'cancelled']); echo 'ok';`)
  }
})
