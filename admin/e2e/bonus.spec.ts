import { expect, test } from '@playwright/test'

import { FIRST_LOAD, PHOTO, signIn, staffApi, websiteBooking } from './helpers'

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §7: a bonus credited on a staff record, a withdrawal asked for under My
 * commission, approved and paid from the Staff screen's queue with a receipt; the badge, the cash book and the staff
 * member's own page follow.
 */
test.describe.configure({ mode: 'serial' })

const PERSON = 'E2E sales_agent'

test('an admin credits a bonus on a staff record, and the staff member asks for a withdrawal under My commission', async ({ page, browser }) => {
  await signIn(page, 'admin')
  await page.goto('/staff')
  await page.getByTestId('staff-table').locator('tbody tr').filter({ hasText: PERSON }).getByRole('link', { name: `Open record — ${PERSON}` }).click(FIRST_LOAD)
  await page.getByRole('button', { name: '+ Credit bonus' }).click(FIRST_LOAD)
  const credit = page.getByRole('dialog', { name: `Credit a bonus · ${PERSON}` })
  await credit.getByLabel('Amount').fill('6000')
  await credit.getByLabel('Reason').fill('Mustang group bonus')
  await credit.getByRole('button', { name: '+ Credit bonus' }).click()
  await expect(page.getByText(`Bonus credited to ${PERSON}`)).toBeVisible()
  await expect(page.getByTestId('bonus-balance')).toContainText('Available BDT 6,000')
  await expect(page.getByTestId('bonus-entries')).toContainText('Mustang group bonus')

  const context = await browser.newContext()
  const staff = await context.newPage()
  await signIn(staff, 'sales_agent')
  await expect(staff.getByRole('navigation').getByRole('link', { name: '◈ My commission' })).toBeVisible()
  await staff.goto('/my-commission')
  await expect(staff.getByTestId('my-commission-kpis')).toContainText('BDT 6,000', FIRST_LOAD)
  const form = staff.getByTestId('withdrawal-form')
  await form.getByLabel('Amount').fill('400')
  await expect(form).toContainText('At least BDT 500')
  await expect(form.getByRole('button', { name: 'Send request' })).toBeDisabled()
  await form.getByLabel('Amount').fill('2500')
  await form.getByLabel('Note (optional)').fill('For Eid')
  await form.getByRole('button', { name: 'Send request' }).click()
  await expect(staff.getByText('Withdrawal requested')).toBeVisible()
  await expect(staff.getByTestId('my-withdrawals')).toContainText('BDT 2,500')
  await expect(staff.getByTestId('my-withdrawals')).toContainText('Pending')
  await expect(staff.getByTestId('my-commission-kpis')).toContainText('BDT 3,500')
  // Their own page only: the company's and other people's figures aren't theirs.
  await expect(staff.getByRole('navigation').getByRole('link', { name: /Staff/ })).toHaveCount(0)
  await context.close()
})

test('the Staff badge opens the waiting withdrawal; the admin approves it and pays it with a receipt, and the cash book shows it', async ({ page, browser }) => {
  await signIn(page, 'admin')
  const badge = page.getByTestId('nav-badge-bonus_withdrawals')
  await expect(badge).toHaveText('1', FIRST_LOAD)
  await badge.click()
  await expect(page).toHaveURL(/\/staff\?withdrawals=open$/)
  const queue = page.getByTestId('bonus-withdrawals')
  await expect(queue).toContainText(PERSON, FIRST_LOAD)
  await expect(queue).toContainText('BDT 2,500')
  await expect(queue).toContainText('For Eid')

  await queue.getByRole('button', { name: `Approve ${PERSON}’s withdrawal` }).click()
  await page.getByRole('dialog', { name: `Approve BDT 2,500 for ${PERSON}` }).getByRole('button', { name: 'Approve' }).click()
  await expect(page.getByText(`${PERSON}’s withdrawal approved`)).toBeVisible()
  // Approved but unpaid is still waiting.
  await expect(badge).toHaveText('1')

  await queue.getByRole('button', { name: `Mark ${PERSON}’s withdrawal paid` }).click()
  const pay = page.getByRole('dialog', { name: `Pay ${PERSON} · BDT 2,500` })
  await pay.getByLabel('Method').selectOption({ label: 'bKash' })
  await pay.getByLabel('Reference').fill('TRX-E2E-BONUS')
  await pay.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await pay.getByRole('button', { name: 'Mark paid' }).click()
  await expect(page.getByText(`${PERSON}’s withdrawal paid · in the cash book under Staff bonuses`)).toBeVisible()
  await expect(badge).toHaveCount(0)

  await page.goto('/transactions')
  const entry = page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: PERSON }).filter({ hasText: 'Bonus withdrawal' })
  await expect(entry).toContainText('− BDT 2,500', FIRST_LOAD)

  const context = await browser.newContext()
  const staff = await context.newPage()
  await signIn(staff, 'sales_agent')
  await staff.goto('/my-commission')
  await expect(staff.getByTestId('my-withdrawals')).toContainText('Paid', FIRST_LOAD)
  await expect(staff.getByTestId('my-commission-kpis')).toContainText('BDT 3,500')
  await context.close()
})

test('an agent claims a booking, its confirmation credits their commission automatically, and cancelling it takes the commission back', async ({ page, browser }) => {
  // Mustang for two: BDT 1,53,000 with 2 % VAT, so 3 % of BDT 1,50,000.
  const { reference } = await websiteBooking(page, 'Commission Customer')
  await signIn(page, 'sales_agent')
  await page.goto('/bookings')
  const row = page.getByTestId('bookings-table').locator('tbody tr').filter({ hasText: reference })
  await row.getByRole('button', { name: 'Claim', exact: true }).click(FIRST_LOAD)
  await expect(page.getByText('It’s yours now')).toBeVisible()
  await row.getByRole('link', { name: reference, exact: true }).click()
  await expect(page.getByRole('heading', { name: reference, level: 1 })).toBeVisible(FIRST_LOAD)
  const bookingId = Number(new URL(page.url()).pathname.split('/').pop())

  const admin = await browser.newContext()
  const adminPage = await admin.newPage()
  const api = await staffApi(adminPage, 'admin')
  await api.post(`admin/bookings/${bookingId}/invoice`)
  await api.postWithReceipt(`admin/bookings/${bookingId}/payments`, { amount: 50000, method: 'bkash', reference: 'BKASH-COMMISSION' })
  // The confirmation renders the invoice PDF inside the request (the e2e queue is synchronous).
  await api.post(`admin/bookings/${bookingId}/confirm`)

  await page.goto('/my-commission')
  const entries = page.getByTestId('bonus-entries')
  await expect(entries).toContainText(`Commission · ${reference}`, FIRST_LOAD)
  await expect(entries).toContainText('3% of BDT 1,50,000 sale before VAT · Automatic')
  await expect(entries).toContainText('+ BDT 4,500')
  const kpis = page.getByTestId('my-commission-kpis')
  await expect(kpis).toContainText('BDT 8,000')
  await expect(kpis).toContainText('Volume bonus this month')
  await expect(kpis).toContainText('1 of 10')
  await expect(kpis).toContainText('9 more confirmed bookings add 0.5% on the whole month')
  await expect(page.getByTestId('commission-rules')).toContainText('You earn 3% of tour sales before VAT (1.5% air, 2% hotel)')

  await api.post(`admin/bookings/${bookingId}/cancel`, { reason: 'Customer changed plans' })
  await admin.close()
  await page.reload()
  await expect(entries).toContainText(`Reversal · ${reference}`, FIRST_LOAD)
  await expect(entries).toContainText('Booking cancelled · Automatic')
  await expect(kpis).toContainText('BDT 3,500')
  await expect(kpis).toContainText('0 of 10')
})
