import { expect, test } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn } from './helpers'

/** docs/phase-5-admin-core.md §4.4: a website enquiry becomes a pool lead; claimed, contacted, lost and reopened. */
test('a website enquiry reaches the board as a new lead; the agent claims it, logs a call and it moves to Contacted', async ({ page }) => {
  const phone = `0171${String(Date.now()).slice(-7)}`
  const name = `Board Lead ${phone.slice(-4)}`
  const response = await page.request.post(`${API_URL}/api/v1/public/inquiries`, {
    headers: { Accept: 'application/json' },
    data: { name, phone, message: 'Kashmir for 12 people?', locale: 'en' },
  })
  expect(response.status()).toBe(202)

  await signIn(page, 'sales_agent')
  await page.getByRole('navigation').getByRole('link', { name: /Customers & leads/ }).click()
  const newColumn = page.getByTestId('lead-column-new')
  const card = newColumn.getByTestId('lead-card').filter({ hasText: name })
  await expect(card).toBeVisible(FIRST_LOAD)
  await expect(card).toContainText('Website')
  await card.click()

  await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible()
  await expect(page.getByText('Not assigned — any sales agent can claim it')).toBeVisible()
  await page.getByRole('button', { name: 'Claim', exact: true }).click()
  await expect(page.getByText('It’s yours now')).toBeVisible()

  await page.getByLabel('Channel').selectOption('call')
  await page.getByLabel('Outcome').selectOption('call_back')
  await page.getByLabel('Note', { exact: true }).fill('Wants prices for 12, calls back Tuesday')
  await page.getByRole('button', { name: 'Add to log' }).click()
  await expect(page.getByTestId('contact-log')).toContainText('Wants prices for 12, calls back Tuesday')

  await page.getByRole('link', { name: 'All customers' }).click()
  await expect(page.getByTestId('lead-column-contacted').getByTestId('lead-card').filter({ hasText: name })).toBeVisible()
  await expect(newColumn.getByTestId('lead-card').filter({ hasText: name })).toHaveCount(0)

  // Lost with a reason, found under the Lost filter, reopened.
  await page.getByTestId('lead-column-contacted').getByTestId('lead-card').filter({ hasText: name }).click()
  await page.getByRole('button', { name: 'Mark lost' }).click()
  await page.getByLabel('Why was the lead lost?').fill('Booked with another agency')
  await page.getByRole('dialog').getByRole('button', { name: 'Mark lost' }).click()
  await expect(page.getByRole('note')).toContainText('Lost: Booked with another agency')
  await page.goto('/customers?state=lost')
  await expect(page.getByTestId('customers-table').locator('tbody tr').filter({ hasText: name })).toBeVisible(FIRST_LOAD)
  await page.getByTestId('customers-table').getByRole('link', { name, exact: true }).click()
  await page.getByRole('button', { name: 'Reopen lead' }).click()
  await expect(page.getByText('Lead reopened')).toBeVisible()
})

test('the customer list shows a passport status chip and never a passport number', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/customers')
  const table = page.getByTestId('customers-table')
  await expect(table.locator('tbody tr').first()).toBeVisible(FIRST_LOAD)
  await expect(table).not.toContainText(/[A-Z]{2}\d{7}/)
  await expect(table.getByText(/Passport on file|Passport expiring|No passport/).first()).toBeVisible()
})
