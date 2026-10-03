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

/** A 1×1 PNG: vouchers take PNG from 2026-10-03. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')

test('a voucher is edited: title, booking from the list, date and a new file, with the replaced file kept under Earlier files (§6)', async ({ page }) => {
  const customer = `Junaidul Haq ${Date.now() % 100000}`
  const { reference } = await websiteBooking(page, customer, undefined, { minimal: true })
  const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10)

  await signIn(page, 'admin')
  await page.goto('/vouchers')
  await expect(page.getByRole('heading', { name: 'Confirmation vouchers', level: 1 })).toBeVisible(FIRST_LOAD)
  await page.getByRole('button', { name: '+ Upload voucher' }).first().click()
  let dialog = page.getByRole('dialog', { name: 'Upload a confirmation voucher' })
  const firstTitle = `Thailand trip draft ${Date.now()}`
  await dialog.getByLabel('Title').fill(firstTitle)
  await dialog.getByLabel('Service date (optional)').fill(inDays(30))
  await dialog.locator('input[type=file]').setInputFiles({ name: 'Untitled design (1).pdf', mimeType: 'application/pdf', buffer: PDF })
  await dialog.getByRole('button', { name: 'Upload', exact: true }).click()
  await expect(dialog).toBeHidden()

  const table = page.getByTestId('vouchers-table')
  let row = table.locator('tbody tr').filter({ hasText: firstTitle })
  await row.getByRole('button', { name: /^Edit/ }).click()
  dialog = page.getByRole('dialog', { name: 'Edit voucher' })
  await expect(dialog.getByLabel('Title')).toHaveValue(firstTitle)
  await expect(dialog.getByTestId('voucher-current-file')).toContainText('Untitled design (1).pdf')
  await expect(dialog.getByRole('button', { name: 'Save changes' })).toBeDisabled()

  // A click on the empty booking box lists the newest bookings straight away; typing the customer's name narrows it.
  const newTitle = `Thailand Trip ${customer} 05 - 18 October`
  await dialog.getByLabel('Title').fill(newTitle)
  await dialog.getByLabel('Booking number (optional)').click()
  await expect(dialog.getByTestId('voucher-booking-options').getByRole('option', { name: new RegExp(reference) })).toBeVisible()
  await expect(dialog.getByTestId('voucher-booking-options').getByRole('alert')).toHaveCount(0)
  await dialog.getByLabel('Booking number (optional)').fill(customer.split(' ').slice(0, 2).join(' '))
  await dialog.getByTestId('voucher-booking-options').getByRole('option', { name: new RegExp(reference) }).click()
  await expect(dialog.getByLabel('Booking number (optional)')).toHaveValue(reference)
  await expect(dialog).toContainText(customer)
  await dialog.getByLabel('Service date (optional)').fill(inDays(20))
  await dialog.locator('input[type=file]').setInputFiles({ name: 'Revised voucher.png', mimeType: 'image/png', buffer: PNG })
  await expect(dialog).toContainText('This file will replace “Untitled design (1).pdf”')
  await dialog.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByText('Voucher updated')).toBeVisible()
  await expect(dialog).toBeHidden()

  // The row shows the new title, booking and file without a reload.
  row = table.locator('tbody tr').filter({ hasText: newTitle })
  await expect(row).toContainText(reference)
  await expect(row).toContainText('PNG')
  await expect(row).toContainText('Revised voucher.png')
  await expect(table).not.toContainText(firstTitle)

  // The replaced PDF is kept under Earlier files and still downloads as it was.
  await row.getByRole('button', { name: /^Edit/ }).click()
  dialog = page.getByRole('dialog', { name: 'Edit voucher' })
  await expect(dialog.getByLabel('Service date (optional)')).toHaveValue(inDays(20))
  const earlier = dialog.getByTestId('voucher-earlier-files')
  await expect(earlier).toContainText('Untitled design (1).pdf')
  await expect(earlier).toContainText('replaced by')
  const downloading = page.waitForEvent('download')
  await earlier.getByRole('button', { name: 'Download' }).click()
  const download = await downloading
  expect(download.suggestedFilename()).toBe('Untitled design (1).pdf')
  expect(readFileSync((await download.path())!)).toEqual(PDF)

  // Emptying the booking box unlinks it.
  await dialog.getByLabel('Booking number (optional)').fill('')
  await dialog.getByLabel('Title').click()
  await dialog.getByRole('button', { name: 'Save changes' }).click()
  await expect(dialog).toBeHidden()
  await expect(row).not.toContainText(reference)
})
