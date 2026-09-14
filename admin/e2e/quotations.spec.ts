import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { API_URL, quotationFor, signIn } from './helpers'

const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights'

/**
 * docs/phase-5-admin-core.md §4.5 and §11: lead → quotation → send → convert → booking, at the price the editor showed;
 * the Quotations badge counts what expires within 48 hours and opens exactly that list.
 */
test('a sales agent quotes a website lead at the website price, sends it, and books it at the quoted price', async ({ page }) => {
  const phone = `0171${String(Date.now()).slice(-7)}`
  const name = `Quote Lead ${phone.slice(-4)}`
  const enquiry = await page.request.post(`${API_URL}/api/v1/public/inquiries`, { headers: { Accept: 'application/json' }, data: { name, phone, message: 'Mustang for two in November?', locale: 'en' } })
  expect(enquiry.status()).toBe(202)

  await signIn(page, 'sales_agent')
  await page.getByRole('navigation').getByRole('link', { name: /Quotations/ }).click()
  await expect(page.getByRole('heading', { name: 'Quotations', level: 1 })).toBeVisible()
  await expect(page.getByTestId('quotation-kpis')).toContainText('Open quotes')

  // A customer record is picked, never typed.
  await page.getByLabel('Customer', { exact: true }).fill(name)
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).click()
  await page.getByLabel('Package', { exact: true }).selectOption(MUSTANG)
  await page.getByLabel('Travellers', { exact: true }).selectOption('2')
  await page.getByLabel('Valid for', { exact: true }).selectOption('7')
  await expect(page.getByTestId('quotation-total')).toHaveText('৳ 1,53,000')

  // A discount shows at once, priced the same way the API prices it.
  await page.getByLabel('Discount (৳)').fill('3000')
  await expect(page.getByTestId('quotation-total')).toHaveText('৳ 1,49,940')
  await page.getByLabel('Discount (৳)').fill('0')
  await expect(page.getByTestId('quotation-total')).toHaveText('৳ 1,53,000')

  await page.getByRole('button', { name: 'Send quote · ৳ 1,53,000' }).click()
  const sent = page.getByText(/QT-\d{4,} sent by WhatsApp and email/)
  await expect(sent).toBeVisible()
  const number = (await sent.textContent())!.match(/QT-\d{4,}/)![0]

  const row = page.getByTestId('quotations-table').locator('tbody tr').filter({ hasText: number })
  await expect(row).toContainText(name)
  await expect(row).toContainText('৳ 1,53,000')
  await expect(row).toContainText('Sent')
  await expect(row.getByRole('link', { name: `WhatsApp — ${number}` })).toHaveAttribute('href', /^https:\/\/wa\.me\/8801\d{9}\?text=/)

  // The lead is Quoted now.
  await page.goto('/customers?state=quoted')
  await expect(page.getByTestId('customers-table').locator('tbody tr').filter({ hasText: name })).toBeVisible()

  // Convert from the row: the travellers' names, then the booking at the frozen price, owned by the agent.
  await page.goto('/quotations')
  await page.getByTestId('quotations-table').locator('tbody tr').filter({ hasText: number }).getByRole('link', { name: `Convert to booking — ${number}` }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText('৳ 1,53,000')
  // No date was fixed on the quotation: the booking needs one.
  await expect(dialog.getByRole('button', { name: 'Create booking' })).toBeDisabled()
  if (await dialog.getByLabel('Departure', { exact: true }).count()) await dialog.getByLabel('Departure', { exact: true }).selectOption({ index: 1 })
  else await dialog.getByLabel('Travel date', { exact: true }).fill(new Date(Date.now() + 45 * 86_400_000).toISOString().slice(0, 10))
  await dialog.getByLabel('Traveller 2').fill('Second Traveller')
  await dialog.getByRole('button', { name: 'Create booking' }).click()

  await expect(page).toHaveURL(/\/bookings\/\d+$/)
  const reference = (await page.getByRole('heading', { level: 1 }).textContent())!.match(/BH-\d{4}-\d{3,}/)?.[0]
  expect(reference).toBeTruthy()
  await expect(page.getByText('৳ 1,53,000').first()).toBeVisible()

  await page.goto('/quotations?status=converted')
  const booked = page.getByTestId('quotations-table').locator('tbody tr').filter({ hasText: number })
  await expect(booked).toContainText('Booked')
  await expect(booked.getByRole('link', { name: reference! })).toBeVisible()
  await expect(booked.getByRole('link', { name: `Convert to booking — ${number}` })).toHaveCount(0)
  await expect(booked.getByRole('button', { name: new RegExp(`^Convert to booking — ${number} \\(Booked as ${reference}\\)$`) })).toBeDisabled()
})

test('the Quotations badge counts quotations expiring within 48 hours, opens that list, and drops when one is withdrawn', async ({ page }) => {
  const soon = await quotationFor(page, 'Expiring Soon Customer')
  await quotationFor(page, 'Plenty Of Time Customer')
  // Valid until today in Dhaka: it ends at midnight, within 48 hours.
  artisan('tinker', `--execute=App\\Models\\Quotation::query()->whereKey(${soon.id})->update(['valid_until' => now('Asia/Dhaka')->toDateString()]); echo 'ok';`)

  await signIn(page, 'admin')
  const badge = page.getByTestId('nav-badge-quotations')
  await expect(badge).toBeVisible()
  const count = Number(await badge.textContent())
  expect(count).toBeGreaterThanOrEqual(1)

  await badge.click()
  await expect(page).toHaveURL(/\/quotations\?status=expiring$/)
  await expect(page.getByRole('radio', { name: `Expiring soon · ${count}` })).toHaveAttribute('aria-checked', 'true')
  const rows = page.getByTestId('quotations-table').locator('tbody tr')
  await expect(rows).toHaveCount(Math.min(count, 30))
  const row = rows.filter({ hasText: soon.number })
  await expect(row).toContainText('Expires today')
  await expect(rows.filter({ hasText: 'Plenty Of Time Customer' })).toHaveCount(0)

  await row.getByRole('button', { name: `Withdraw — ${soon.number}`, exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText(`${soon.number} withdrawn`)).toBeVisible()
  if (count === 1) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(count - 1))
  await expect(rows.filter({ hasText: soon.number })).toHaveCount(0)
})

test('revising a sent quotation opens a new draft that sending replaces the original with', async ({ page }) => {
  const original = await quotationFor(page, 'Revision Customer')
  await signIn(page, 'admin')
  await page.goto(`/quotations/${original.id}`)
  await expect(page.getByRole('heading', { name: original.number, level: 1 })).toBeVisible()

  await page.getByTestId('quotation-actions').getByRole('button', { name: 'Revise' }).click()
  await expect(page.getByText(/Revision QT-\d{4,} started/)).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(original.number)
  const revision = (await page.getByRole('heading', { level: 1 }).textContent())!.trim()
  await expect(page.getByRole('link', { name: original.number })).toBeVisible()

  // Three travellers now: re-priced live, saved, sent.
  await page.getByLabel('Travellers', { exact: true }).selectOption('3')
  const total = (await page.getByTestId('quotation-total').textContent())!.trim()
  expect(total).not.toBe('৳ 1,53,000')
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByText('Saved')).toBeVisible()
  await page.getByTestId('quotation-actions').getByRole('button', { name: 'Send quote' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText(`${revision} sent by WhatsApp and email`)).toBeVisible()
  await expect(page.getByTestId('quotation-total')).toHaveText(total)

  await page.getByRole('link', { name: original.number }).click()
  await expect(page.getByText('Withdrawn', { exact: true }).first()).toBeVisible()
})
