import { expect, test } from '@playwright/test'

import { signIn, websiteBooking } from './helpers'

/**
 * docs/phase-5-admin-core.md §3.1: a sidebar badge is derived from data. Clicking it lands on a list of exactly that
 * many, and it changes as soon as a related action does — no reload.
 */
test('the Bookings badge opens a list of exactly its size and drops when an inquiry is deleted', async ({ page }) => {
  await websiteBooking(page, 'Badge Check One')
  await websiteBooking(page, 'Badge Check Two')
  await signIn(page, 'admin')

  const badge = page.getByTestId('nav-badge-bookings')
  await expect(badge).toBeVisible()
  const before = Number(await badge.textContent())
  expect(before).toBeGreaterThanOrEqual(2)

  await badge.click()
  await expect(page).toHaveURL(/\/bookings\?status=inquiry$/)
  await expect(page.getByRole('radio', { name: `Inquiry · ${before}` })).toHaveAttribute('aria-checked', 'true')
  const rows = page.getByTestId('bookings-table').locator('tbody tr')
  await expect(rows).toHaveCount(Math.min(before, 30))

  // Delete a booking made by mistake: the badge follows without a reload.
  const row = rows.filter({ hasText: 'Badge Check Two' })
  const reference = (await row.locator('td').first().getByRole('link').first().textContent())!.trim()
  await row.getByRole('button', { name: `Delete — ${reference}`, exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText(`${reference} deleted`)).toBeVisible()
  await expect(badge).toHaveText(String(before - 1))
  await expect(page.getByRole('radio', { name: `Inquiry · ${before - 1}` })).toBeVisible()
  await expect(rows).toHaveCount(Math.min(before - 1, 30))
})

test('a sales agent’s badge counts their own inquiries and the pool, and claiming keeps it equal to the list', async ({ page }) => {
  await websiteBooking(page, 'Pool Lead For Agent')
  await signIn(page, 'sales_agent')

  const badge = page.getByTestId('nav-badge-bookings')
  const count = Number(await badge.textContent())
  await badge.click()
  await expect(page.getByTestId('bookings-table').locator('tbody tr')).toHaveCount(Math.min(count, 30))

  const row = page.getByTestId('bookings-table').locator('tbody tr').filter({ hasText: 'Pool Lead For Agent' })
  await expect(row.getByText('Pool', { exact: true })).toBeVisible()
  // Contact buttons wait for the claim: only the owner works the customer.
  await expect(row.getByRole('button', { name: /^WhatsApp — .*\(Claim this booking first\)$/ })).toBeDisabled()
  await row.getByRole('button', { name: 'Claim', exact: true }).click()
  await expect(page.getByText('It’s yours now')).toBeVisible()
  await expect(row.getByRole('link', { name: /^WhatsApp — / })).toBeVisible()
  await expect(badge).toHaveText(String(count))
})
