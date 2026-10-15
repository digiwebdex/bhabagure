import { expect, test, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'

import { API_URL, FIRST_LOAD, PHOTO, signIn, staffApi, websiteBooking } from './helpers'

/**
 * docs/payment-requests.md: a customer sends "I've paid" with a screenshot from their booking page; the accountant sees it
 * in Payment requests (with the sidebar badge) and on the booking, approves it — the payment is recorded and the inquiry
 * confirmed — and rejects a second one with a reason.
 */
test.describe.configure({ mode: 'serial' })

const BRAC = { bankName: 'BRAC Bank', accountName: 'Example Holidays', accountNumber: '2020000000002', branch: 'Gulshan', routingNumber: '060260002', transferType: 'NPSB' }

async function sendRequest(page: Page, reference: string, token: string, amount: number, bankReference: string) {
  const response = await page.request.post(`${API_URL}/api/v1/public/bookings/${reference}/payment-requests`, {
    headers: { Accept: 'application/json', 'X-Booking-Token': token },
    multipart: {
      method: 'bank_transfer',
      account_number: BRAC.accountNumber,
      amount: String(amount),
      paid_on: new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()),
      reference: bankReference,
      screenshot: { name: 'transfer.jpg', mimeType: 'image/jpeg', buffer: readFileSync(PHOTO) },
    },
  })
  expect(response.status(), await response.text()).toBe(201)
}

test('a customer’s bank payment is approved from Payment requests and its booking; another is rejected with a reason', async ({ page }) => {
  // The office's two accounts, as Site settings → Payment holds them.
  const admin = await staffApi(page, 'admin')
  await admin.put('admin/settings/payment', { value: { banks: [{ ...BRAC, bankName: 'Mutual Trust Bank', accountNumber: '1310000000001', routingNumber: '145260001' }, BRAC], link: null, bkash: { number: '+8801613000000', chargePercent: 1.3 } } })

  const suffix = String(Date.now()).slice(-6)
  const { reference, token } = await websiteBooking(page, `PAYREQ ${suffix}`, undefined, { minimal: true })
  await sendRequest(page, reference, token, 50000, `NPSB-${suffix}`)
  await sendRequest(page, reference, token, 1000, `WRONG-${suffix}`)

  await signIn(page, 'accountant')
  await page.getByRole('link', { name: /Payment requests/ }).click()
  await expect(page.getByRole('heading', { name: 'Payment requests' })).toBeVisible(FIRST_LOAD)
  const list = page.getByTestId('payment-request-list')
  await expect(list).toContainText(`NPSB-${suffix}`, FIRST_LOAD)
  await list.getByRole('link', { name: reference }).first().click()

  // On the booking: both requests, waiting; the screenshot opens.
  const card = page.getByTestId('booking-payment-requests')
  await expect(card).toContainText(`NPSB-${suffix}`, FIRST_LOAD)
  const good = card.locator('li').filter({ hasText: `NPSB-${suffix}` })
  const popup = page.waitForEvent('popup')
  await good.getByRole('button', { name: 'Open screenshot' }).click()
  await (await popup).close()

  await good.getByRole('button', { name: 'Approve' }).click()
  const approve = page.getByRole('dialog', { name: 'Approve the payment of BDT 50,000' })
  await expect(approve.getByLabel('Amount received')).toHaveValue('50000')
  await approve.getByRole('button', { name: 'Approve and record payment' }).click()
  await expect(page.getByText('Payment of BDT 50,000 recorded')).toBeVisible(FIRST_LOAD)
  await expect(good).toContainText('Approved', FIRST_LOAD)
  // The inquiry is confirmed and the payment is on its books.
  await expect(page.getByText('Confirmed').first()).toBeVisible()

  const wrong = card.locator('li').filter({ hasText: `WRONG-${suffix}` })
  await wrong.getByRole('button', { name: 'Reject' }).click()
  const reject = page.getByRole('dialog', { name: 'Reject the payment of BDT 1,000' })
  await reject.getByLabel('Reason').fill('Not in our BRAC Bank statement')
  await reject.getByRole('button', { name: 'Reject' }).click()
  await expect(page.getByText('Payment request rejected')).toBeVisible(FIRST_LOAD)
  await expect(wrong).toContainText('Not in our BRAC Bank statement', FIRST_LOAD)

  // The customer's booking page data shows both outcomes.
  const booking = await page.request.get(`${API_URL}/api/v1/public/bookings/${reference}`, { headers: { Accept: 'application/json', 'X-Booking-Token': token } })
  const requests = ((await booking.json()) as { data: { paymentRequests: { status: string; note: string | null }[] } }).data.paymentRequests
  expect(requests.map((row) => row.status).sort()).toEqual(['approved', 'rejected'])
})
