import { expect, test, type Page } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn, staffApi, websiteBooking } from './helpers'

/**
 * docs/coupons.md: Admin → Marketing → Coupons, a customer's coupon on a booking's draft invoice and the printed invoice,
 * and the coupon report. Mustang for two is BDT 1,53,000.
 */

const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights'

/** A website booking with a coupon, as the booking form makes it: learn the API's total, then book at it. */
async function bookWithCoupon(page: Page, code: string, phone: string) {
  const data = {
    package_slug: MUSTANG,
    travel_date: new Date(Date.now() + 70 * 86_400_000).toISOString().slice(0, 10),
    pax: 2,
    room: 'twin',
    addons: [],
    travellers: [{ name: 'Coupon Customer', phone }, {}],
    terms_accepted: true,
    locale: 'en',
    coupon_code: code,
  }
  const quote = await page.request.post(`${API_URL}/api/v1/public/bookings`, { headers: { Accept: 'application/json' }, data: { ...data, expected_total: 0 } })
  expect(quote.status()).toBe(409)
  const total = ((await quote.json()) as { quote: { total: number } }).quote.total
  const booked = await page.request.post(`${API_URL}/api/v1/public/bookings`, { headers: { Accept: 'application/json' }, data: { ...data, expected_total: total } })
  expect(booked.status(), await booked.text()).toBe(201)
  return ((await booked.json()) as { data: { reference: string } }).data.reference
}

test('an admin creates a coupon, switches it off and on, and a used one is archived rather than deleted', async ({ page }) => {
  await signIn(page, 'admin')
  // The sidebar's Marketing group; a link's name carries its icon ("✂ Coupons").
  await page.getByRole('link', { name: /Coupons$/ }).click()
  await expect(page.getByRole('heading', { name: 'Coupons', level: 1 })).toBeVisible(FIRST_LOAD)

  await page.getByRole('button', { name: '+ New coupon' }).click()
  const dialog = page.getByRole('dialog', { name: 'New coupon' })
  // A code that breaks the rules is refused under its field.
  await dialog.getByLabel('Coupon code').fill('x!')
  await dialog.getByLabel('Coupon or campaign name').fill('Eid 2026')
  await dialog.getByLabel('Discount (%)').fill('10')
  await dialog.getByRole('button', { name: 'Create coupon' }).click()
  await expect(dialog.getByText('Use letters, digits and dashes, 3 to 30 long.')).toBeVisible()

  await dialog.getByLabel('Coupon code').fill('eid-fb-e2e')
  await expect(dialog.getByLabel('Coupon code')).toHaveValue('EID-FB-E2E')
  await dialog.getByLabel('Maximum discount (BDT)').fill('20000')
  await dialog.getByLabel('Total uses').fill('50')
  await dialog.getByLabel('Campaign channel').selectOption('facebook')
  await dialog.getByRole('button', { name: 'Create coupon' }).click()
  await expect(page.getByText('Coupon EID-FB-E2E created')).toBeVisible()

  const table = page.getByTestId('coupons-table')
  const row = table.locator('tbody tr').filter({ hasText: 'EID-FB-E2E' })
  await expect(row).toContainText('Eid 2026 · Facebook')
  await expect(row).toContainText('10% off · up to BDT 20,000')
  await expect(row).toContainText('0 used / 50')
  await expect(row).toContainText('Active')

  await row.getByRole('button', { name: 'Switch off — EID-FB-E2E' }).click()
  await expect(page.getByText('EID-FB-E2E switched off')).toBeVisible()
  await expect(row).toContainText('Switched off')
  await row.getByRole('button', { name: 'Switch on — EID-FB-E2E' }).click()
  await expect(row).toContainText('Active')

  // A customer books with it on the website: it holds a use until the booking is confirmed.
  await bookWithCoupon(page, 'eid-fb-e2e', `0181${String(Date.now()).slice(-7)}`)
  await page.reload()
  await expect(row).toContainText('0 used · 1 pending / 50', FIRST_LOAD)

  // Used, so it can only be archived — kept for the booking that names it.
  await row.getByRole('button', { name: 'Archive — EID-FB-E2E' }).click()
  await page.getByRole('dialog', { name: 'Are you sure?' }).getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText('EID-FB-E2E archived')).toBeVisible()
  await expect(table.locator('tbody tr').filter({ hasText: 'EID-FB-E2E' })).toHaveCount(0)
  await page.getByRole('radio', { name: /^Archived/ }).click()
  await table.locator('tbody tr').filter({ hasText: 'EID-FB-E2E' }).getByRole('button', { name: 'Restore — EID-FB-E2E' }).click()
  await expect(page.getByText('EID-FB-E2E restored')).toBeVisible()
})

test('staff apply a customer’s coupon on the draft invoice; the invoice prints it and the report counts it once confirmed', async ({ page }) => {
  const api = await staffApi(page, 'admin')
  await api.post('admin/coupons', {
    code: 'PHONE5000', name: 'Phone offer', kind: 'public', channel: 'other', discount_type: 'fixed', discount_value: 5000,
    applies_to: 'all', package_ids: [], per_customer_limit: 1, is_active: true,
  })
  const { reference } = await websiteBooking(page, 'Coupon Caller', undefined, { minimal: true })

  await signIn(page, 'admin')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference, exact: true }).click()
  await expect(page.getByRole('heading', { name: reference, level: 1 })).toBeVisible(FIRST_LOAD)
  const quote = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Quote' }) })
  await expect(quote).toContainText('BDT 1,53,000')

  // A wrong code is refused with the reason; the right one comes off before the 2%: 1,45,000 + 2,900.
  await quote.getByLabel('Coupon code').fill('NOPE')
  await quote.getByRole('button', { name: 'Apply coupon' }).click()
  await expect(quote.getByRole('alert')).toContainText('This coupon code isn\'t valid.')
  await quote.getByLabel('Coupon code').fill('phone5000')
  await quote.getByRole('button', { name: 'Apply coupon' }).click()
  await expect(page.getByText('Coupon PHONE5000 applied')).toBeVisible()
  await expect(quote.getByTestId('booking-coupon')).toContainText('PHONE5000')
  await expect(quote.getByTestId('booking-coupon')).toContainText('held by this booking · applied by staff')
  await expect(quote).toContainText('Coupon PHONE5000')
  await expect(quote).toContainText('− BDT 5,000')
  await expect(quote).toContainText('BDT 1,47,900')

  // Removed: the full price again; applied again for the rest of the test.
  await quote.getByRole('button', { name: 'Remove coupon' }).click()
  await expect(page.getByText('Coupon removed')).toBeVisible()
  await expect(quote).toContainText('BDT 1,53,000')
  await quote.getByLabel('Coupon code').fill('PHONE5000')
  await quote.getByRole('button', { name: 'Apply coupon' }).click()
  await expect(quote).toContainText('BDT 1,47,900')

  // A third traveller: the coupon stays 5,000; the staff's own discount is extra. 72,750 × 3 = 2,18,250 − 5,000 − 1,000 = 2,12,250 + 4,245.
  await quote.getByRole('button', { name: 'One traveller more' }).click()
  await quote.getByLabel('Extra discount (BDT)').fill('1000')
  await expect(quote).toContainText('BDT 2,16,495')
  await quote.getByRole('button', { name: 'Save quote' }).click()
  await expect(page.getByText('Quote saved')).toBeVisible()

  // Issued: the invoice prints the coupon as its own line, and the staff discount apart.
  const invoice = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Invoice' }) })
  await invoice.getByRole('button', { name: 'Issue invoice' }).click()
  await expect(page.getByText('Invoice issued')).toBeVisible()
  const preview = page.frameLocator('iframe[title="Invoice preview"]')
  await expect(preview.locator('.totals')).toContainText('Coupon discount (PHONE5000)')
  await expect(preview.locator('.totals')).toContainText('− BDT 5,000')
  await expect(preview.locator('.totals')).toContainText('Discount')
  await expect(preview.locator('.totals')).toContainText('BDT 2,16,495')
  await expect(quote.getByRole('button', { name: 'Remove coupon' })).toHaveCount(0)

  // Paid and confirmed through the API; the report then counts it as used.
  const id = Number(new URL(page.url()).pathname.split('/').pop())
  await api.postWithReceipt(`admin/bookings/${id}/payments`, { amount: 50000, method: 'cash' })
  await api.post(`admin/bookings/${id}/confirm`)

  await page.goto('/coupons/report')
  await expect(page.getByRole('heading', { name: 'Coupon report', level: 1 })).toBeVisible(FIRST_LOAD)
  const couponRow = page.getByTestId('coupon-report-coupons').locator('tbody tr').filter({ hasText: 'PHONE5000' })
  await expect(couponRow).toContainText('BDT 5,000')
  await expect(couponRow).toContainText('BDT 2,16,495')
  // Applied, removed and applied again: two uses of this booking, one given back and one used.
  const uses = page.getByTestId('coupon-report-uses')
  await expect(uses.locator('tbody tr').filter({ hasText: reference })).toHaveCount(2)
  await page.getByRole('radio', { name: 'Used' }).click()
  // The filtered list may wait behind the page's first requests on the one-request-at-a-time e2e API.
  await expect(page.getByText('1 use', { exact: true })).toBeVisible(FIRST_LOAD)
  await expect(uses.locator('tbody tr').filter({ hasText: reference })).toContainText('Used')
  await page.getByRole('radio', { name: 'Given back' }).click()
  await expect(uses.locator('tbody tr').filter({ hasText: reference })).toContainText('removed by staff', FIRST_LOAD)
})

test('accountants see coupons without changing them; sales agents don’t see them', async ({ page }) => {
  await (await staffApi(page, 'admin')).post('admin/coupons', {
    code: 'LOOK-ONLY', name: 'Seen by accountants', kind: 'public', channel: null, discount_type: 'percent', discount_value: 5,
    applies_to: 'all', package_ids: [], is_active: true,
  })
  await signIn(page, 'accountant')
  await page.goto('/coupons')
  await expect(page.getByRole('heading', { name: 'Coupons', level: 1 })).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('button', { name: '+ New coupon' })).toHaveCount(0)
  const row = page.getByTestId('coupons-table').locator('tbody tr').filter({ hasText: 'LOOK-ONLY' })
  await expect(row.getByRole('button', { name: /^Edit — LOOK-ONLY/ })).toBeDisabled()

  await signIn(page, 'sales_agent')
  await expect(page.getByRole('link', { name: /Bookings$/ })).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('link', { name: /Coupons$/ })).toHaveCount(0)
})
