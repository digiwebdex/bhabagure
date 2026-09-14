import { expect, test } from '@playwright/test'

import { signIn, staffApi, websiteBooking } from './helpers'

/**
 * docs/phase-5-admin-core.md §4.2: the Dashboard is computed, not written — a cash entry and a website booking show up
 * at once — and a role sees only the widgets it is allowed to (the API leaves the others out).
 */
test('the dashboard shows what just happened, and Collected is the same figure as on Payments', async ({ page }) => {
  const { reference } = await websiteBooking(page, 'Dashboard Visitor')
  await signIn(page, 'admin')
  await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()

  await expect(page.getByTestId('recent-bookings-table').getByRole('link', { name: reference, exact: true })).toBeVisible()
  await expect(page.getByTestId('kpi-leads')).toBeVisible()

  // Collected is one figure: the Dashboard card and the Payments card agree to the taka.
  const collected = (await page.getByTestId('kpi-collected').locator('span').nth(1).getAttribute('title'))!
  await page.getByTestId('kpi-collected').click()
  await expect(page).toHaveURL(/\/payments$/)
  await expect(page.getByTestId('payments-collected')).toHaveText(collected)

  // A new lead raises New leads on the next visit.
  const api = await staffApi(page, 'admin')
  await page.goto('/')
  const before = Number((await page.getByTestId('kpi-leads').locator('span').nth(1).textContent())!.replace(/\D/g, ''))
  await api.post('admin/customers', { name: 'Dashboard Lead', phone: `0171${String(Date.now()).slice(-7)}`, email: null, source: 'walk_in', interest: null })
  await page.reload()
  await expect(page.getByTestId('kpi-leads').locator('span').nth(1)).toHaveText(String(before + 1))
})

test('a sales agent’s dashboard has no money figures', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
  await expect(page.getByTestId('kpi-departures')).toBeVisible()
  await expect(page.getByTestId('kpi-bookings')).toBeVisible()
  await expect(page.getByTestId('kpi-collected')).toHaveCount(0)
  await expect(page.getByTestId('dashboard-destinations')).toHaveCount(0)
})
