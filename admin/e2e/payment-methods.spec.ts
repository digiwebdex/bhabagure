import { expect, test } from '@playwright/test'

import { FIRST_LOAD, PHOTO, signIn, websiteBooking } from './helpers'

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.F: staff enter the bank account, payment link and bKash number in
 * Site settings, and a bKash payment that carried bKash's charge is recorded as the tour payment plus that charge.
 */
test('the payment methods are entered in Site settings, and a bKash payment records its charge separately', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Payment', exact: true }) })
  await expect(card.getByLabel('Bank', { exact: true })).toBeVisible(FIRST_LOAD)

  // A routing number is 9 digits: the API says so before anything is saved.
  await card.getByLabel('Bank', { exact: true }).fill('Example Trust Bank')
  await card.getByLabel('Account name').fill('Example Holidays')
  await card.getByLabel('Account number').fill('1310000000001')
  await card.getByLabel('Branch').fill('Mirpur')
  await card.getByLabel('Routing number').fill('14526')
  await card.getByLabel('Transfer type').fill('NPSB')
  await card.getByLabel('bKash number').fill('+8801613000000')
  await card.getByLabel('bKash charge (%)').fill('1.3')
  await card.getByRole('button', { name: 'Save' }).click()
  await expect(card.getByRole('alert').first()).toBeVisible()
  await card.getByLabel('Routing number').fill('145260001')
  await card.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Saved', { exact: true }).first()).toBeVisible()

  // A booking with an issued invoice, then the bKash payment the customer sent with the charge on top.
  const { reference } = await websiteBooking(page, 'Bkash Payer')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference, exact: true }).click()
  const invoice = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Invoice' }) })
  await invoice.getByRole('button', { name: 'Issue invoice' }).click()
  await expect(page.getByText('Invoice issued')).toBeVisible(FIRST_LOAD)

  const payments = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Payments' }) })
  await payments.getByRole('button', { name: 'Record payment' }).click()
  const dialog = page.getByRole('dialog', { name: 'Record payment' })
  await dialog.getByLabel('Amount').fill('10000')
  await dialog.getByLabel('Method').selectOption('bkash')
  // 1.3% of BDT 10,000 = BDT 130, and the charge needs the bKash transaction ID.
  const chargeSwitch = dialog.getByRole('switch', { name: 'Customer also paid the BDT 130 bKash charge' })
  await chargeSwitch.click()
  await dialog.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await dialog.getByRole('button', { name: 'Record payment' }).click()
  await expect(dialog.getByText('Enter the bKash transaction ID to record the bKash charge')).toBeVisible()

  await dialog.getByLabel('Reference').fill(`TRX${Date.now()}`)
  await dialog.getByRole('button', { name: 'Record payment' }).click()
  await expect(dialog).toBeHidden()
  // The booking is paid BDT 10,000; the BDT 130 is a charge beside it, on the invoice too.
  await expect(payments).toContainText('BDT 10,000')
  await expect(payments).toContainText('bKash · bKash charge')
  await expect(payments).toContainText('BDT 130')
})
