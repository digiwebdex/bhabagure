import { expect, test } from '@playwright/test'

import { signIn, websiteBooking } from './helpers'

/** docs/phase-3-booking.md §6: the draft quote, the printed invoice with header on/off, and payments from the ledger. */

test('draft quote, issue, header on and off, record payment, confirm', async ({ page }) => {
  const { reference } = await websiteBooking(page, 'Arif Chowdhury', 'arif@example.test')
  await signIn(page, 'admin')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference }).click()
  await expect(page.getByRole('heading', { name: reference, level: 1 })).toBeVisible()

  // Draft controls: 3 travellers → slab −3% → 72,750 × 3 = 2,18,250; 5% VAT after a 1,000 discount = 10,863 → 2,28,113.
  const quote = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Quote' }) })
  await quote.getByRole('button', { name: 'One traveller more' }).click()
  await quote.getByLabel('Service charge & VAT').selectOption('5')
  await quote.getByLabel('Discount (৳)').fill('1000')
  await expect(quote).toContainText('BDT 2,28,113')
  await quote.getByRole('button', { name: 'Save quote' }).click()
  await expect(page.getByText('Quote saved')).toBeVisible()
  await expect(quote).toContainText('BDT 2,28,113')

  // Issue: the preview shows the number; header off leaves the letterhead out.
  const invoice = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Invoice' }) })
  await invoice.getByRole('button', { name: 'Issue invoice' }).click()
  await expect(page.getByText('Invoice issued')).toBeVisible()
  const preview = page.frameLocator('iframe[title="Invoice preview"]')
  await expect(preview.locator('main')).toContainText(/INV-\d{4}/)
  await expect(preview.locator('header.letterhead')).toContainText('Bhabaghure Holidays Aviation')
  await invoice.getByRole('switch').click()
  await expect(preview.locator('[data-region="letterhead"]')).toBeEmpty()
  await expect(quote.getByRole('button', { name: 'Save quote' })).toHaveCount(0)

  // Record a half advance: status follows the ledger, never a typed paid amount.
  const payments = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Payments' }) })
  await payments.getByRole('button', { name: 'Record payment' }).click()
  const dialog = page.getByRole('dialog', { name: 'Record payment' })
  await dialog.getByRole('button', { name: /^Half advance/ }).click()
  await dialog.getByLabel('Method').selectOption('bkash')
  await dialog.getByLabel('Reference').fill(`E2E${Date.now()}`)
  await dialog.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText('Payment recorded')).toBeVisible()
  await expect(payments.getByText('PARTIAL')).toBeVisible()
  await expect(preview.locator('main')).toContainText('PARTIAL')

  await page.getByRole('button', { name: 'Confirm booking' }).click()
  await expect(page.getByText('Booking confirmed', { exact: true })).toBeVisible()
  await expect(page.getByRole('main').getByText('Confirmed', { exact: true }).first()).toBeVisible()

  // The half advance sent "payment received"; confirming sends the confirmation. Each channel reports on its own. No
  // notifications number is published in this spec, so WhatsApp to the customer is held — and because the confirmation
  // matters to money, it went by SMS instead of silently going email-only.
  const groups = page.locator('section').filter({ has: page.getByRole('heading', { name: 'WhatsApp, email & SMS' }) }).getByTestId('notification-group')
  await expect(groups.filter({ hasText: 'Payment received' }).first()).toBeVisible()
  const confirmation = groups.filter({ hasText: 'Booking confirmed + invoice' })
  await expect(confirmation.getByTestId('channel-whatsapp')).toContainText('Not sent')
  await expect(confirmation.getByTestId('channel-whatsapp')).toContainText('the notifications number isn’t published')
  await expect(confirmation.getByTestId('channel-email')).toContainText('Sent')
  await expect(confirmation.getByTestId('channel-sms')).toContainText('Submitted')
  await expect(confirmation.getByTestId('channel-sms')).toContainText('Sent because WhatsApp couldn’t deliver')
})
