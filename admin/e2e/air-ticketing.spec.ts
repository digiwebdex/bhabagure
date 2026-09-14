import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { API_URL, signIn } from './helpers'

/**
 * docs/phase-5-admin-core.md §4.7: the website's air-ticket enquiries in a queue — flagged after 24 hours, counted by
 * the badge, claimed from the pool, contacted by WhatsApp or email (never SMS) and marked quoted.
 */
test('a website air enquiry waiting over 24 hours is flagged, claimed, answered and marked quoted; the badge follows', async ({ page }) => {
  const phone = `0171${String(Date.now()).slice(-7)}`
  const name = `Air Passenger ${phone.slice(-4)}`
  const departOn = new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10)
  const sent = await page.request.post(`${API_URL}/api/v1/public/air-quotes`, {
    headers: { Accept: 'application/json' },
    data: { from_place: 'Dhaka', to_place: 'Bangkok', depart_on: departOn, passengers: 2, cabin_class: 'economy', name, phone, email: 'air.passenger@example.test', locale: 'en' },
  })
  expect(sent.status()).toBe(202)
  // It arrived 30 hours ago.
  artisan('tinker', `--execute=App\\Models\\Inquiry::query()->where('name', '${name}')->update(['created_at' => now()->subHours(30)]); echo 'ok';`)

  await signIn(page, 'sales_agent')
  const badge = page.getByTestId('nav-badge-air_inquiries')
  await expect(badge).toBeVisible()
  const count = Number(await badge.textContent())
  await badge.click()
  await expect(page).toHaveURL(/\/air-ticketing\?state=open&stale=1$/)
  const rows = page.getByTestId('air-inquiries-table').locator('tbody tr')
  await expect(rows).toHaveCount(Math.min(count, 30))

  const row = rows.filter({ hasText: name })
  await expect(row.getByTestId('stale-flag')).toHaveText('Waiting 30 h')
  await expect(row).toContainText('Dhaka → Bangkok')
  await expect(row.getByText('Pool', { exact: true })).toBeVisible()
  // No SMS in this queue; contact waits for the claim.
  await expect(row.getByRole('link', { name: new RegExp(`^Send SMS — ${name}`) })).toHaveCount(0)
  await expect(row.getByRole('button', { name: new RegExp(`^Send SMS — ${name}`) })).toHaveCount(0)
  await expect(row.getByRole('button', { name: `WhatsApp — ${name} (Claim this enquiry first)` })).toBeDisabled()

  await row.getByRole('button', { name: 'Claim', exact: true }).click()
  await expect(page.getByText('It’s yours now')).toBeVisible()
  await expect(row.getByRole('link', { name: `WhatsApp — ${name}` })).toHaveAttribute('href', /^https:\/\/wa\.me\/8801\d{9}\?text=/)
  await expect(row.getByRole('link', { name: `Email — ${name}` })).toHaveAttribute('href', /^mailto:air\.passenger@example\.test/)

  await row.getByRole('button', { name: `Mark as quoted — ${name}` }).click()
  await expect(page.getByText(`${name}’s enquiry marked quoted`)).toBeVisible()
  if (count === 1) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(count - 1))
  await expect(rows.filter({ hasText: name })).toHaveCount(0)

  // In the Quoted list, with who marked it; back to open undoes it.
  await page.getByRole('radio', { name: 'Quoted', exact: true }).click()
  const quoted = page.getByTestId('air-inquiries-table').locator('tbody tr').filter({ hasText: name })
  await expect(quoted).toContainText('E2E sales_agent')
  await quoted.getByRole('button', { name: `Back to open — ${name}` }).click()
  await expect(page.getByText(`${name}’s enquiry is open again`)).toBeVisible()
  await expect(badge).toHaveText(String(count))
})
