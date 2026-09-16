import { expect, test } from '@playwright/test'

import { PHOTO, signIn, websiteBooking } from './helpers'

/** docs/phase-3-booking.md §6: the draft quote, the printed invoice with header on/off, and payments from the ledger. */

test('draft quote, issue, header on and off, record payment, confirm', async ({ page }) => {
  const { reference } = await websiteBooking(page, 'Arif Chowdhury', 'arif@example.test')
  await signIn(page, 'admin')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference, exact: true }).click()
  await expect(page.getByRole('heading', { name: reference, level: 1 })).toBeVisible()

  // Draft controls: 3 travellers → slab −3% → 72,750 × 3 = 2,18,250; 5% VAT after a 1,000 discount = 10,863 → 2,28,113.
  const quote = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Quote' }) })
  await quote.getByRole('button', { name: 'One traveller more' }).click()
  await quote.getByLabel('Service charge & VAT').selectOption('5')
  await quote.getByLabel('Discount (BDT)').fill('1000')
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
  // A typed payment carries its receipt: the button waits for it.
  await expect(dialog.getByRole('button', { name: 'Record payment' })).toBeDisabled()
  await dialog.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await dialog.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText('Payment recorded')).toBeVisible()
  await expect(payments.getByText('PARTIAL')).toBeVisible()
  await expect(payments.getByRole('button', { name: '⎘ Receipt' })).toBeVisible()
  await expect(preview.locator('main')).toContainText('PARTIAL')

  await page.getByRole('button', { name: 'Confirm booking' }).click()
  // The e2e queue is synchronous: the confirmation email's invoice PDF is rendered inside this request.
  await expect(page.getByText('Booking confirmed', { exact: true })).toBeVisible({ timeout: 30_000 })
  await expect(page.getByRole('main').getByText('Confirmed', { exact: true }).first()).toBeVisible()

  // The half advance sent "payment received"; confirming sends the confirmation. Each channel reports on its own. No
  // notifications number is published in this spec, so WhatsApp to the customer is held — and because the confirmation
  // matters to money, it went by SMS instead of silently going email-only.
  const groups = page.locator('section').filter({ has: page.getByRole('heading', { name: 'WhatsApp, email & SMS' }) }).getByTestId('notification-group')
  await expect(groups.filter({ hasText: 'Payment received' }).first()).toBeVisible()
  const confirmation = groups.filter({ hasText: 'Booking confirmed + invoice' })
  await expect(confirmation.getByTestId('channel-whatsapp')).toContainText('Not sent')
  await expect(confirmation.getByTestId('channel-whatsapp')).toContainText('the notifications number isn’t published')
  // The log mailer (as on production before SendGrid) takes the email without delivering it; the card says so.
  await expect(confirmation.getByTestId('channel-email')).toContainText('Not delivered')
  await expect(confirmation.getByTestId('channel-sms')).toContainText('Submitted')
  await expect(confirmation.getByTestId('channel-sms')).toContainText('Sent because WhatsApp couldn’t deliver')
})

test('a website booking with only the lead’s name and WhatsApp number is completed by staff', async ({ page }) => {
  const { reference } = await websiteBooking(page, 'Rafiq Islam', undefined, { minimal: true })
  await signIn(page, 'admin')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference, exact: true }).click()
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Customer & travellers' }) })
  const second = card.locator('li').filter({ hasText: 'Traveller 2' })
  await expect(second).toContainText('Still needed: passport, date of birth')

  await card.getByRole('button', { name: 'Edit details of Traveller 2' }).click()
  const dialog = page.getByRole('dialog', { name: 'Traveller 2' })
  await dialog.getByLabel('Name (as on passport)').fill('Shirin Akter')
  await dialog.getByLabel('Passport number').fill('BX4471228')
  await dialog.getByLabel('Passport expiry').fill('2031-07-11')
  await dialog.getByLabel('Date of birth').fill('2099-01-01')
  await dialog.getByRole('button', { name: 'Save' }).click()
  await expect(dialog.getByText(/must be a date before today/)).toBeVisible()
  await expect(page.getByText('Traveller details saved')).toHaveCount(0)
  await dialog.getByLabel('Date of birth').fill('1994-11-02')
  await dialog.getByRole('button', { name: 'Save' }).click()

  await expect(page.getByText('Traveller details saved')).toBeVisible()
  const completed = card.locator('li').filter({ hasText: 'Shirin Akter' })
  await expect(completed).toContainText('BX4471228')
  await expect(completed).not.toContainText('Still needed')
  await expect(card.locator('li').filter({ hasText: 'Rafiq Islam' })).toContainText('Still needed: passport, date of birth')
})
