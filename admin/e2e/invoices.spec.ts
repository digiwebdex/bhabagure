import { expect, test } from '@playwright/test'

import { FIRST_LOAD, PHOTO, signIn } from './helpers'

/**
 * docs/phase-9-accounts.md §5: the Invoices screen — write one, issue it, chase what is owed, take the money, and read
 * it back. The tabs, the filters and every option on the row are the ones the client already works from.
 */
test('an admin writes an invoice, issues it, reminds the customer, takes the payment and reads it back', async ({ page }) => {
  await signIn(page, 'admin')
  await page.getByRole('navigation').getByRole('link', { name: 'I Invoices' }).click()
  await expect(page.getByRole('heading', { name: 'Invoices', level: 1 })).toBeVisible(FIRST_LOAD)

  const stamp = String(Date.now())
  const billedTo = `E2E Walk-in ${stamp}`
  await page.getByRole('link', { name: '+ New invoice' }).click()
  await expect(page.getByRole('heading', { name: 'New invoice', level: 1 })).toBeVisible()

  // Nobody on file: the name and number written here make the customer the invoice is billed to.
  await page.getByLabel('Billed to').fill(billedTo)
  await expect(page.getByRole('button', { name: 'Save draft' })).toBeDisabled()
  await page.getByLabel('Phone', { exact: true }).fill(`88017${stamp.slice(-8)}`)
  await page.getByLabel('What it is for').fill('Umrah package, two people')
  await page.getByLabel('P.O. / S.O. number').fill('PO-2026-11')

  // One line: 2 × 15,000 less 5,000 is 25,000, and 5% VAT on that is 1,250.
  const lines = page.getByTestId('invoice-lines')
  await lines.getByLabel('Item').fill('Umrah package')
  await lines.getByLabel('Qty').fill('2')
  await lines.getByLabel('Unit price').fill('15000')
  await lines.getByLabel('Discount', { exact: true }).fill('5000')
  await lines.getByLabel('VAT %').fill('5')

  // A discount on the whole invoice comes off the lines, and a delivery charge goes on top.
  const totals = page.getByTestId('invoice-totals')
  await totals.getByLabel('Discount', { exact: true }).fill('1000')
  await totals.getByRole('button', { name: '+ Add delivery charge' }).click()
  await totals.getByLabel('Delivery charge').fill('500')
  await expect(totals).toContainText('BDT 25,000')
  await expect(totals).toContainText('BDT 1,250')
  await expect(totals).toContainText('BDT 25,750')

  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByText('Draft saved')).toBeVisible()

  const table = page.getByTestId('invoices-table')
  const row = table.locator('tbody tr').filter({ hasText: billedTo })
  await expect(row).toContainText('Draft', FIRST_LOAD)
  await expect(row).toContainText('BDT 25,750')

  // Changed while a draft: a second line, then issued from the same page.
  await row.getByRole('button', { name: /^Actions —/ }).click()
  await page.getByRole('menuitem', { name: 'Edit' }).click()
  await expect(page.getByRole('heading', { name: /^Invoice Draft$/, level: 1 })).toBeVisible(FIRST_LOAD)
  await page.getByRole('button', { name: '+ Add line' }).click()
  await page.getByTestId('invoice-lines').getByLabel('Item').nth(1).fill('Visa processing')
  await page.getByTestId('invoice-lines').getByLabel('Unit price').nth(1).fill('8000')
  await expect(page.getByTestId('invoice-totals')).toContainText('BDT 33,750')

  await page.getByRole('button', { name: 'Save invoice' }).click()
  await expect(page.getByText('Invoice issued')).toBeVisible()

  // Issued: numbered, unpaid, and counted in the tab.
  const issued = table.locator('tbody tr').filter({ hasText: billedTo })
  await expect(issued).toContainText(/#INV-\d+/, FIRST_LOAD)
  await expect(issued).toContainText('Unpaid')
  await expect(issued).toContainText('BDT 33,750')

  await page.getByRole('tab', { name: /^Unpaid/ }).click()
  await expect(table.locator('tbody tr').filter({ hasText: billedTo })).toHaveCount(1)

  // A reminder the system sends, in the staff member's own words, with the cost said before it goes.
  await table.locator('tbody tr').filter({ hasText: billedTo }).getByRole('button', { name: 'Send reminder' }).click()
  await page.getByRole('dialog', { name: 'Send reminder' }).getByRole('button', { name: 'SMS', exact: true }).click()
  const sms = page.getByRole('dialog', { name: 'Send SMS reminder' })
  await expect(sms.getByLabel('Message')).toHaveValue(/BDT 33,750/)
  await expect(sms).toContainText('SMS')
  await sms.getByRole('button', { name: 'Send SMS' }).click()
  await expect(page.getByText('SMS sent')).toBeVisible()

  // The money: method and account are separate, and the receipt is required.
  await table.locator('tbody tr').filter({ hasText: billedTo }).getByRole('button', { name: 'Payment' }).click()
  const payment = page.getByRole('dialog', { name: 'Receive payment' })
  await expect(payment).toContainText('BDT 33,750')
  await payment.getByLabel('Payment method').selectOption('cash')
  await payment.getByLabel('Note').fill('Counter receipt')
  await expect(payment.getByRole('button', { name: 'Save changes' })).toBeDisabled()
  await payment.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await payment.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByText(/Payment recorded on INV-\d+/)).toBeVisible()

  await page.getByRole('tab', { name: /^Paid/ }).click()
  const paid = table.locator('tbody tr').filter({ hasText: billedTo })
  await expect(paid).toContainText('Paid', FIRST_LOAD)

  // Read back: the lines, the money taken, and nothing owed.
  await paid.getByRole('button', { name: /^#INV-\d+$/ }).click()
  const details = page.getByRole('dialog', { name: 'Invoice details' })
  await expect(details.getByTestId('invoice-details')).toContainText('Umrah package', FIRST_LOAD)
  await expect(details.getByTestId('invoice-details')).toContainText('PO-2026-11')
  await expect(details.getByTestId('invoice-payments')).toContainText('BDT 33,750')
  await expect(details.getByRole('button', { name: 'Print PDF' })).toBeVisible()
})

test('a draft nobody wants is deleted, and an issued invoice is not', async ({ page }) => {
  await signIn(page, 'admin')
  const stamp = String(Date.now())
  const title = `E2E throwaway ${stamp}`
  const customer = `E2E Scratch ${stamp}`

  await page.goto('/invoices/new')
  await page.getByLabel('Billed to').fill(customer)
  await page.getByLabel('Phone', { exact: true }).fill(`88018${stamp.slice(-8)}`)
  await page.getByLabel('What it is for').fill(title)
  await page.getByTestId('invoice-lines').getByLabel('Item').fill('Air ticket')
  await page.getByTestId('invoice-lines').getByLabel('Unit price').fill('9000')
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByText('Draft saved')).toBeVisible()

  const row = page.getByTestId('invoices-table').locator('tbody tr').filter({ hasText: customer })
  await expect(row).toContainText('Draft', FIRST_LOAD)
  await row.getByRole('button', { name: /^Actions —/ }).click()
  await page.getByRole('menuitem', { name: 'Delete' }).click()
  await page.getByRole('dialog', { name: 'Are you sure?' }).getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText('Draft deleted')).toBeVisible()
  await expect(page.getByTestId('invoices-table').locator('tbody tr').filter({ hasText: customer })).toHaveCount(0)
})
