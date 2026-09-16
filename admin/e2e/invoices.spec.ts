import { expect, test } from '@playwright/test'

import { FIRST_LOAD, PHOTO, signIn } from './helpers'

/**
 * docs/phase-9-accounts.md §5: an invoice staff write themselves — lines with their own discount and VAT, a draft that
 * can be changed, and then issued, numbered and counted among the unpaid.
 */
test('an admin writes an invoice, changes the draft, issues it and finds it under Unpaid', async ({ page }) => {
  await signIn(page, 'admin')
  await page.getByRole('navigation').getByRole('link', { name: 'I Invoices' }).click()
  await expect(page.getByRole('heading', { name: 'Invoices', level: 1 })).toBeVisible(FIRST_LOAD)

  const stamp = String(Date.now())
  const billedTo = `E2E Walk-in ${stamp}`
  await page.getByRole('button', { name: '+ New invoice' }).click()
  const editor = page.getByRole('dialog', { name: 'New invoice' })
  // Nobody on file: the name and number written here make the customer the invoice is billed to.
  await editor.getByLabel('Billed to').fill(billedTo)
  await expect(editor.getByRole('button', { name: 'Save draft' })).toBeDisabled()
  await editor.getByLabel('Phone').fill(`88017${stamp.slice(-8)}`)
  await editor.getByLabel('What it is for').fill('Umrah package, two people')

  // One line: 2 × 15,000 less 5,000 is 25,000, and 5% VAT on that is 1,250.
  const lines = editor.getByTestId('invoice-lines')
  await lines.getByLabel('Item').fill('Umrah package')
  await lines.getByLabel('Qty').fill('2')
  await lines.getByLabel('Unit price').fill('15000')
  await lines.getByLabel('Discount', { exact: true }).fill('5000')
  await lines.getByLabel('VAT %').fill('5')

  // A discount on the whole invoice comes off the lines, and the VAT is worked out before it.
  const totals = editor.getByTestId('invoice-totals')
  await totals.getByLabel('Discount', { exact: true }).fill('1000')
  await expect(totals).toContainText('BDT 25,000')
  await expect(totals).toContainText('BDT 1,250')
  await expect(totals).toContainText('BDT 25,250')

  await editor.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByText('Draft saved')).toBeVisible()

  const table = page.getByTestId('invoices-table')
  const row = table.locator('tbody tr').filter({ hasText: billedTo })
  await expect(row).toContainText('Draft', FIRST_LOAD)
  await expect(row).toContainText('BDT 25,250')
  // Nothing to share until it is issued.
  await expect(row.getByRole('button', { name: 'PDF — Draft (A draft has nothing to share yet)' })).toBeDisabled()

  // Changed while a draft: a second line, and the totals follow.
  await row.click()
  const draft = page.getByRole('dialog', { name: 'Invoice Draft' })
  await draft.getByRole('button', { name: '+ Add line' }).click()
  await draft.getByTestId('invoice-lines').getByLabel('Item').nth(1).fill('Visa processing')
  await draft.getByTestId('invoice-lines').getByLabel('Unit price').nth(1).fill('8000')
  await expect(draft.getByTestId('invoice-totals')).toContainText('BDT 33,250')

  await draft.getByRole('button', { name: 'Issue invoice' }).click()
  await expect(page.getByText('Invoice issued')).toBeVisible()

  // Issued: numbered, unpaid, and counted in the tab.
  const issued = table.locator('tbody tr').filter({ hasText: billedTo })
  await expect(issued).toContainText(/INV-\d+/, FIRST_LOAD)
  await expect(issued).toContainText('Unpaid')
  await expect(issued).toContainText('BDT 33,250')

  await page.getByRole('tab', { name: /^Unpaid/ }).click()
  await expect(table.locator('tbody tr').filter({ hasText: billedTo })).toHaveCount(1)

  // The figures are frozen, and the customer's copy can be shared.
  await table.locator('tbody tr').filter({ hasText: billedTo }).click()
  const frozen = page.getByRole('dialog', { name: /^Invoice INV-\d+$/ })
  await expect(frozen.getByLabel('What it is for')).toBeDisabled()
  await expect(frozen.getByRole('button', { name: 'Issue invoice' })).toHaveCount(0)
  await expect(frozen.getByRole('link', { name: 'PDF' })).toBeVisible()
  await expect(frozen.getByTestId('invoice-totals')).toContainText('BDT 33,250')
  await frozen.getByLabel('Close').click()

  // Money received against it goes through the same endpoint the Payments screen uses, receipt and all.
  await table.locator('tbody tr').filter({ hasText: billedTo }).getByRole('button', { name: /^Record payment — INV-\d+$/ }).click()
  const payment = page.getByRole('dialog', { name: /^Payment on INV-\d+$/ })
  await expect(payment).toContainText('BDT 33,250')
  await payment.getByLabel('Method').selectOption('cash')
  await payment.getByLabel('Reference').fill('Counter receipt')
  await expect(payment.getByRole('button', { name: 'Record payment' })).toBeDisabled()
  await payment.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await payment.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText(/Payment recorded on INV-\d+/)).toBeVisible()

  await page.getByRole('tab', { name: /^Paid/ }).click()
  const paid = table.locator('tbody tr').filter({ hasText: billedTo })
  await expect(paid).toContainText('Paid', FIRST_LOAD)
  await expect(paid.getByRole('button', { name: /^Record payment — INV-\d+ \(Nothing is owed on this invoice\)$/ })).toBeDisabled()
})
