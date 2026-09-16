import { expect, test } from '@playwright/test'

import { FIRST_LOAD, PHOTO, signIn } from './helpers'

/**
 * docs/phase-9-accounts.md §7: Transactions — the cash book with the account it is read for, money written in by hand,
 * VAT handed over, and the tick that says somebody has checked an entry. Nothing here is ever edited: a mistake is
 * corrected with a reversing entry, and the balance follows both ways.
 */
test('an admin records a cash out with its receipt, checks it off, reverses it, and the balance follows', async ({ page }) => {
  await signIn(page, 'admin')
  await page.getByRole('navigation').getByRole('link', { name: /Transactions$/ }).click()
  await expect(page.getByRole('heading', { name: 'Transactions', level: 1 })).toBeVisible(FIRST_LOAD)

  // The company balance is what the account picker says it is.
  const picker = page.getByLabel('Account', { exact: true })
  // The balance is only named once it has arrived, so wait for it rather than reading a placeholder.
  await expect(picker.locator('option').first()).toHaveText(/All accounts \(/, FIRST_LOAD)
  const before = (await picker.locator('option').first().textContent())!.trim()

  const description = `E2E office rent ${Date.now()}`
  await page.getByRole('button', { name: 'Cash out', exact: true }).click()
  const form = page.getByRole('dialog', { name: 'Add cash out' })
  await form.getByLabel('Account', { exact: true }).selectOption('1000')
  await form.getByLabel('Category').selectOption('office_rent')
  await form.getByLabel('Amount').fill('35000')
  await form.getByLabel('Description').fill(description)
  await expect(form.getByRole('button', { name: 'Save cash out' })).toBeDisabled()
  await form.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await form.getByRole('button', { name: 'Save cash out' }).click()
  await expect(page.getByText('Entry saved')).toBeVisible()

  const row = page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: description })
  await expect(row).toContainText('− BDT 35,000', FIRST_LOAD)
  await expect(row).toContainText('Cash out')
  await expect(row).toContainText('Cash in hand', { timeout: 10_000 })
  await expect(picker.locator('option').first()).not.toHaveText(before)

  // Ticking it off records who looked; the entry itself is untouched, so it can still be reversed afterwards.
  await row.getByRole('button', { name: /^Mark #\d+ as checked$/ }).click()
  await expect(row.getByRole('button', { name: /^Take back the check on #\d+$/ })).toBeVisible()

  await row.getByRole('button', { name: /^Actions — #\d+$/ }).click()
  await page.getByRole('menuitem', { name: 'Reverse…' }).click()
  const dialog = page.getByRole('dialog', { name: /^Reverse/ })
  await dialog.getByLabel('Reason').fill('Posted twice')
  await dialog.getByRole('button', { name: 'Reverse…' }).click()
  await expect(page.getByText('Reversing entry added')).toBeVisible()
  await expect(page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: /Reversal of #\d+/ }).first()).toBeVisible()
  await expect(picker.locator('option').first()).toHaveText(before, { timeout: 10_000 })
})

test('VAT collected is handed over to the government, which is the only thing that settles it', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/transactions')
  await expect(page.getByRole('heading', { name: 'Transactions', level: 1 })).toBeVisible(FIRST_LOAD)

  await page.getByRole('button', { name: /^More/ }).click()
  await page.getByRole('menuitem', { name: 'VAT payment' }).click()
  const form = page.getByRole('dialog', { name: 'VAT payment' })
  await form.getByLabel('Account', { exact: true }).selectOption('1010')
  await form.getByLabel('Amount').fill('500')
  await form.getByLabel('Description').fill('E2E VAT return')
  await form.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await expect(form.getByRole('button', { name: 'Pay' })).toBeEnabled()
  await form.getByRole('button', { name: 'Pay' }).click()
  await expect(page.getByText('VAT payment recorded')).toBeVisible()

  await expect(page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: 'E2E VAT return' })).toContainText('− BDT 500', FIRST_LOAD)
})

test('staff without payment permissions have no Transactions screen', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await expect(page.getByRole('navigation').getByRole('link', { name: /Transactions$/ })).toHaveCount(0)
  await page.goto('/transactions')
  await expect(page.getByTestId('cash-book-table')).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Cash out', exact: true })).toHaveCount(0)
})
