import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'

import { expectPdfTab, FIRST_LOAD, PHOTO, signIn, websiteBooking } from './helpers'

/**
 * docs/booking-vouchers.md: Sales → Vouchers — a supplier's confirmation voucher uploaded as a PDF or JPG, listed by its
 * service date, opened in the browser or downloaded under its own name, shown on its booking, and archived with a reason.
 */

const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n')

test('a voucher is uploaded, listed by date, opened, downloaded, shown on its booking and archived', async ({ page }) => {
  const { reference } = await websiteBooking(page, 'Voucher Customer', undefined, { minimal: true })
  const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10)

  await signIn(page, 'admin')
  await page.getByRole('link', { name: /Vouchers$/ }).click()
  await expect(page.getByRole('heading', { name: 'Confirmation vouchers', level: 1 })).toBeVisible(FIRST_LOAD)
  await expect(page.getByText('No upcoming vouchers')).toBeVisible(FIRST_LOAD)

  // A PDF linked to the booking, and a JPG contract without either, a little later.
  await page.getByRole('button', { name: '+ Upload voucher' }).first().click()
  let dialog = page.getByRole('dialog', { name: 'Upload a confirmation voucher' })
  await dialog.getByLabel('Title').fill('Hotel Himalaya — Kathmandu, 3 rooms')
  await dialog.getByLabel('Booking number (optional)').fill(reference.toLowerCase())
  await dialog.getByLabel('Service date (optional)').fill(inDays(40))
  await dialog.locator('input[type=file]').setInputFiles({ name: 'Himalaya voucher.pdf', mimeType: 'application/pdf', buffer: PDF })
  await dialog.getByRole('button', { name: 'Upload', exact: true }).click()
  await expect(page.getByText('Voucher uploaded')).toBeVisible()

  await page.getByRole('button', { name: '+ Upload voucher' }).first().click()
  dialog = page.getByRole('dialog', { name: 'Upload a confirmation voucher' })
  await dialog.getByLabel('Title').fill('Green Line bus contract')
  await dialog.getByLabel('Service date (optional)').fill(inDays(10))
  await dialog.locator('input[type=file]').setInputFiles({ name: 'contract.jpg', mimeType: 'image/jpeg', buffer: readFileSync(PHOTO) })
  await dialog.getByRole('button', { name: 'Upload', exact: true }).click()
  await expect(dialog).toBeHidden()

  // Upcoming, soonest first.
  const table = page.getByTestId('vouchers-table')
  await expect(table.locator('tbody tr')).toHaveCount(2)
  await expect(table.locator('tbody tr').first()).toContainText('Green Line bus contract')
  const row = table.locator('tbody tr').filter({ hasText: 'Hotel Himalaya' })
  await expect(row).toContainText(reference)
  await expect(row).toContainText('Himalaya voucher.pdf')

  // A click opens the PDF in a new tab; the download keeps its own name.
  await expectPdfTab(page, /\/admin\/vouchers\/\d+\/file$/, () => row.getByRole('button', { name: 'Hotel Himalaya — Kathmandu, 3 rooms', exact: true }).click())
  const downloading = page.waitForEvent('download')
  await row.getByRole('button', { name: /^Download/ }).click()
  const download = await downloading
  expect(download.suggestedFilename()).toBe('Himalaya voucher.pdf')
  expect(readFileSync((await download.path())!)).toEqual(PDF)

  // On its booking.
  await row.getByRole('link', { name: reference }).click()
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Confirmation vouchers' }) })
  await expect(card.getByTestId('booking-vouchers')).toContainText('Hotel Himalaya — Kathmandu, 3 rooms', FIRST_LOAD)

  // Archived with a reason: gone from Upcoming, kept under Archived.
  await page.goBack()
  await row.getByRole('button', { name: /^Archive/ }).click()
  const archive = page.getByRole('dialog', { name: /^Archive “Hotel Himalaya/ })
  await archive.getByLabel('Reason').fill('Hotel changed')
  await archive.getByRole('button', { name: 'Archive' }).click()
  await expect(page.getByText('Voucher archived')).toBeVisible()
  await expect(table.locator('tbody tr')).toHaveCount(1)
  await page.getByRole('radio', { name: /^Archived · 1/ }).click()
  await expect(table).toContainText('Hotel changed')
})

test('a sales agent reads and downloads vouchers but can’t upload or archive them', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await page.getByRole('link', { name: /Vouchers$/ }).click()
  await expect(page.getByRole('heading', { name: 'Confirmation vouchers', level: 1 })).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('button', { name: '+ Upload voucher' })).toHaveCount(0)
})
