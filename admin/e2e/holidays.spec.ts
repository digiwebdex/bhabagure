import { expect, test, type Page } from '@playwright/test'

import { FIRST_LOAD, signIn } from './helpers'

/**
 * docs/holiday-calendar.md: the super admin adds a one-day holiday and a date range, can't overlap them, changes a range,
 * and copies a fixed-date holiday to the next year; a sales agent sees the calendar but can't change it. Years well
 * ahead keep this clear of anything else the run adds. Each save waits for the calendar to reload before its dialog
 * closes, which on the one-request-at-a-time e2e API can pass the default 5 s: those waits use FIRST_LOAD.
 */
test.describe.configure({ mode: 'serial' })

const day = (page: Page, iso: string) => page.getByTestId('holiday-grid').locator(`[data-day="${iso}"]`)

async function addHoliday(page: Page, from: string, to: string | null, en: string, bn: string) {
  await page.getByRole('button', { name: '+ Add holiday' }).click()
  const dialog = page.getByRole('dialog', { name: 'Add a holiday' })
  await dialog.getByLabel('From').fill(from)
  if (to) await dialog.getByLabel('To').fill(to)
  await dialog.getByLabel('Name (English)').fill(en)
  await dialog.getByLabel('Name (Bangla)').fill(bn)
  return dialog
}

test('the super admin keeps the calendar: a day, a range, no overlap, a change and a copy to next year', async ({ page }) => {
  await signIn(page, 'super_admin')
  await page.goto('/holidays?year=2031')
  await expect(page.getByTestId('holiday-year')).toHaveText('2031', FIRST_LOAD)
  await expect(page.getByText('No holidays for 2031 yet.')).toBeVisible(FIRST_LOAD)

  // One day.
  let dialog = await addHoliday(page, '2031-02-21', null, 'Shaheed Day', 'শহীদ দিবস')
  await expect(dialog.getByTestId('holiday-days')).toHaveText('1 day')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Shaheed Day added')).toBeVisible(FIRST_LOAD)
  await expect(day(page, '2031-02-21')).toHaveAttribute('data-kind', 'holiday')

  // A range: one holiday of five days.
  dialog = await addHoliday(page, '2031-03-20', '2031-03-24', 'Eid-ul-Fitr', 'ঈদুল ফিতর')
  await expect(dialog.getByTestId('holiday-days')).toHaveText('5 days')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Eid-ul-Fitr added')).toBeVisible(FIRST_LOAD)
  const list = page.getByTestId('holiday-list')
  await expect(list.locator('li').filter({ hasText: 'Eid-ul-Fitr' })).toContainText('5 days')
  for (const iso of ['2031-03-20', '2031-03-22', '2031-03-24']) await expect(day(page, iso)).toHaveAttribute('data-kind', 'holiday')

  // Never on top of another holiday.
  dialog = await addHoliday(page, '2031-03-24', null, 'Clash', 'সংঘাত')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(dialog).toContainText('Another holiday already falls on one of these days.', FIRST_LOAD)
  await dialog.getByRole('button', { name: 'Cancel' }).click()

  // A changed range gives its last day back.
  await list.getByRole('button', { name: 'Edit Eid-ul-Fitr' }).click()
  dialog = page.getByRole('dialog', { name: 'Edit Eid-ul-Fitr' })
  await dialog.getByLabel('To').fill('2031-03-23')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Eid-ul-Fitr saved')).toBeVisible(FIRST_LOAD)
  await expect(day(page, '2031-03-24')).not.toHaveAttribute('data-kind', 'holiday')
  await expect(page.getByTestId('holiday-summary')).toContainText('2 holidays')
  await expect(page.getByTestId('holiday-summary')).toContainText('5 days off for holidays')

  // Next year: the fixed-date holiday copied onto the same day; Eid left for a hand entry.
  await page.getByRole('button', { name: 'Next year' }).click()
  await expect(page.getByTestId('holiday-year')).toHaveText('2032')
  await page.getByRole('button', { name: 'Copy from 2031' }).click()
  dialog = page.getByRole('dialog', { name: 'Copy holidays from 2031 to 2032' })
  await dialog.getByLabel('Shaheed Day').check()
  await dialog.getByRole('button', { name: 'Copy 1 holiday' }).click()
  // The copy saves, then reloads both years before the dialog closes: several requests on the one-at-a-time e2e API.
  await expect(page.getByText('1 holiday copied')).toBeVisible(FIRST_LOAD)
  await expect(day(page, '2032-02-21')).toHaveAttribute('data-kind', 'holiday')
  await expect(page.getByTestId('holiday-list')).not.toContainText('Eid-ul-Fitr')

  // Removed: the day is a working day again.
  await page.getByTestId('holiday-list').getByRole('button', { name: 'Remove Shaheed Day' }).click()
  await page.getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText('Shaheed Day removed')).toBeVisible(FIRST_LOAD)
  await expect(day(page, '2032-02-21')).toHaveAttribute('data-kind', 'working')
})

test('every staff member sees the calendar, read-only', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await expect(page.getByRole('link', { name: 'Holiday calendar' })).toBeVisible(FIRST_LOAD)
  await page.goto('/holidays?year=2031')
  const list = page.getByTestId('holiday-list')
  // A full page load queues refresh, me, nav counts and the calendar on the one-at-a-time e2e API.
  await expect(list).toContainText('Shaheed Day', { timeout: 30_000 })
  await expect(list).toContainText('Eid-ul-Fitr')
  await expect(day(page, '2031-03-21')).toHaveAttribute('data-kind', 'holiday')
  // Fridays are the weekly day off.
  await expect(day(page, '2031-03-07')).toHaveAttribute('data-kind', 'off')
  await expect(page.getByRole('button', { name: '+ Add holiday' })).toHaveCount(0)
  await expect(list.getByRole('button', { name: /^Edit / })).toHaveCount(0)
})
