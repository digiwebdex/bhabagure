import { expect, test } from '@playwright/test'

import { FIRST_LOAD, signIn } from './helpers'

/**
 * docs/phase-9-accounts.md: the Accounting menu — a chart of accounts staff extend, the journal behind every figure
 * with entries that must balance and can only be reversed, and the reports that read it.
 */
test('the accountant keeps the chart of accounts, posts a balanced entry and reverses it, and the reports agree', async ({ page }) => {
  await signIn(page, 'admin')
  // The admin may look at the books but not change them; only the accountant posts.
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('link', { name: 'A Chart of accounts' })).toBeVisible(FIRST_LOAD)
  await page.goto('/accounts')
  await expect(page.getByRole('button', { name: '+ New account' })).toHaveCount(0)

  await signIn(page, 'accountant')
  await page.goto('/accounts')
  await expect(page.getByRole('heading', { name: 'Chart of accounts', level: 1 })).toBeVisible(FIRST_LOAD)
  // Cash is the software's own account: marked, and offered no Delete.
  const cash = page.getByTestId('accounts-asset').locator('li').filter({ hasText: 'Cash' }).first()
  await expect(cash).toContainText('Built in')
  await expect(cash.getByRole('button', { name: 'Delete' })).toHaveCount(0)

  // A staff account takes the next free number in its range.
  await page.getByRole('button', { name: '+ New account' }).click()
  const dialog = page.getByRole('dialog', { name: 'New account' })
  await dialog.getByLabel('Account name').fill('Bank charges')
  await dialog.getByLabel('Kind').selectOption('expense')
  await dialog.getByRole('button', { name: 'Save' }).click()
  const charges = page.getByTestId('accounts-expense').locator('li').filter({ hasText: 'Bank charges' })
  await expect(charges).toContainText('5500', FIRST_LOAD)

  // A journal entry must balance before it can be posted.
  await page.goto('/journal')
  await page.getByRole('button', { name: '+ New entry' }).click()
  const entry = page.getByRole('dialog', { name: 'New journal entry' })
  await entry.getByLabel('Description').fill('September bank charges')
  const lines = entry.getByTestId('journal-lines')
  await lines.getByLabel('Account').first().selectOption({ label: '5500 · Bank charges' })
  await lines.getByLabel('Debit').first().fill('850')
  await lines.getByLabel('Account').nth(1).selectOption({ label: '2100 · VAT and service charge payable' })
  await expect(entry.getByText('Out of balance by BDT 850')).toBeVisible()
  await expect(entry.getByRole('button', { name: 'Post entry' })).toBeDisabled()

  await lines.getByLabel('Credit').nth(1).fill('850')
  await expect(entry.getByText('Balanced')).toBeVisible()
  await entry.getByRole('button', { name: 'Post entry' }).click()
  const row = page.getByTestId('journal-entries').locator('tbody').filter({ hasText: 'September bank charges' })
  await expect(row).toContainText('Staff entry', FIRST_LOAD)
  await expect(row).toContainText('BDT 850')

  // The chart shows it, the reports agree, and the entry can be reversed exactly once.
  await page.goto('/accounts')
  await expect(page.getByTestId('accounts-expense').locator('li').filter({ hasText: 'Bank charges' })).toContainText('BDT 850')

  await page.goto('/reports/general-ledger')
  await expect(page.getByText('Debits equal credits')).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('general-ledger')).toContainText('Bank charges')

  await page.goto('/journal')
  await page.getByTestId('journal-entries').locator('tbody').filter({ hasText: 'September bank charges' }).getByRole('button', { name: 'Reverse' }).click()
  const reverse = page.getByRole('dialog', { name: /^Reverse entry/ })
  await reverse.getByLabel('Why').fill('Charged to the wrong month')
  await reverse.getByRole('button', { name: 'Reverse' }).click()
  await expect(page.getByTestId('journal-entries')).toContainText('Reverses #', FIRST_LOAD)

  await page.goto('/accounts')
  await expect(page.getByTestId('accounts-expense').locator('li').filter({ hasText: 'Bank charges' })).toContainText('BDT 0')
})
