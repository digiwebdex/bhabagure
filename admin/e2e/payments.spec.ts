import { expect, test } from '@playwright/test'

import { PHOTO, signIn } from './helpers'

/**
 * docs/phase-5-admin-core.md §4.6: a manual entry with a receipt reaches the cash book and the balance, and is corrected
 * only by a reversing entry; a deal's advance and due come from the ledger.
 */
test('an admin posts a cash out with a receipt, finds it in the cash book and reverses it; the balance follows', async ({ page }) => {
  await signIn(page, 'admin')
  await page.getByRole('navigation').getByRole('link', { name: /Payments & invoices/ }).click()
  await expect(page.getByRole('heading', { name: 'Payments & invoices', level: 1 })).toBeVisible()
  const balance = page.getByTestId('company-balance')
  await expect(balance).toBeVisible()
  const before = (await balance.locator('span').nth(1).textContent())!.trim()

  const form = page.getByTestId('manual-entry')
  const description = `E2E office rent ${Date.now()}`
  await form.getByRole('radio', { name: 'Cash out' }).click()
  await form.getByLabel('Method').selectOption('cash')
  await form.getByLabel('Category').selectOption('office_rent')
  await form.getByLabel('Source').selectOption('office')
  await form.getByLabel('Description').fill(description)
  await form.getByLabel('Amount (৳)').fill('35000')
  await form.getByLabel('Attach a receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await form.getByRole('button', { name: 'লেজারে যোগ করুন · Post to ledger' }).click()
  await expect(page.getByText('Posted to the cash book · receipt attached: photo.jpg')).toBeVisible()
  await expect(balance.locator('span').nth(1)).not.toHaveText(before)

  const row = page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: description })
  await expect(row).toContainText('− ৳ 35,000')
  await expect(row).toContainText('Receipt')
  await expect(row.getByRole('button', { name: /^Receipt — #\d+$/ })).toBeEnabled()
  await expect(row.getByRole('button', { name: /^Edit — #\d+ \(Entries are never edited — reverse instead\)$/ })).toBeDisabled()

  await row.getByRole('button', { name: /^Reverse… — #\d+$/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Reason').fill('Posted twice')
  await dialog.getByRole('button', { name: 'Reverse…' }).click()
  await expect(page.getByText('Reversing entry added')).toBeVisible()
  await expect(page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: /Reversal of #\d+/ }).first()).toBeVisible()
  await expect(row.getByRole('button', { name: /^Reverse… — #\d+ \(Already reversed\)$/ })).toBeDisabled()
  await expect(balance.locator('span').nth(1)).toHaveText(before)
})

test('a deal with an advance shows what is due, takes the rest and is marked paid', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/payments')
  const company = `E2E Corporate ${Date.now()}`
  const form = page.getByTestId('new-deal')
  await form.getByLabel('Client or company name').fill(company)
  await form.getByLabel('What the deal is for').fill('Sales team incentive tour')
  await form.getByLabel('Deal total').fill('200000')
  await form.getByLabel('Advance received').fill('50000')
  await form.getByLabel('Method').selectOption('bank_transfer')
  await expect(form).toContainText('Invoice for ৳ 2,00,000: ৳ 50,000 received now, ৳ 1,50,000 due.')
  await form.getByRole('button', { name: 'Add deal' }).click()
  await expect(page.getByText(/Deal INV-\d{4,} created/)).toBeVisible()

  const deal = page.getByTestId('deal').filter({ hasText: company })
  await expect(deal).toContainText('PARTIAL')
  await expect(deal).toContainText('৳ 1,50,000')
  await deal.getByLabel('Receive').fill('150000')
  await deal.getByLabel('Method').selectOption('bkash')
  await deal.getByRole('button', { name: 'Record payment' }).click()
  await expect(page.getByText(/Payment recorded on INV-\d{4,}/)).toBeVisible()
  await expect(page.getByTestId('deal').filter({ hasText: company })).toHaveCount(0)

  await page.getByRole('radio', { name: 'Paid', exact: true }).click()
  await expect(page.getByTestId('deal').filter({ hasText: company })).toContainText('PAID')
  await expect(page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: company })).toHaveCount(2)
})

test('staff without payment permissions have no Payments screen', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await expect(page.getByRole('navigation').getByRole('link', { name: /Payments & invoices/ })).toHaveCount(0)
  await page.goto('/payments')
  await expect(page.getByTestId('company-balance')).toHaveCount(0)
  await expect(page.getByTestId('cash-book-table')).toHaveCount(0)
})
