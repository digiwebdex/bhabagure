import { expect, test } from '@playwright/test'

import { FIRST_LOAD, signIn } from './helpers'

const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10)

/**
 * docs/invoice-services.md: a line says what it is for; once the invoice is saved a ticket shows in Air ticketing and a
 * hotel booking in Hotel requests. A departure within a week is flagged; a schedule change is recorded there, the
 * WhatsApp message carries it, and the invoice shows and changes the same record.
 */
test('an invoice with a ticket and a hotel fills both lists, and a schedule change reaches WhatsApp and the invoice', async ({ page }) => {
  test.setTimeout(120_000)
  const stamp = String(Date.now())
  const name = `Ticket Buyer ${stamp.slice(-5)}`
  const pnr = `E${stamp.slice(-5)}`
  const hotel = `Sea View ${stamp.slice(-5)}`

  await signIn(page, 'admin')
  await page.goto('/invoices/new')
  await page.getByLabel('Billed to').fill(name)
  await page.getByLabel('Phone', { exact: true }).fill(`017${stamp.slice(-8, -6)}-${stamp.slice(-6)}`)
  await page.getByLabel('What it is for').fill('Bangkok trip')

  const lines = page.getByTestId('invoice-lines')
  const first = page.getByTestId('line-service').first()
  await lines.getByLabel('Item').fill('Air ticket')
  await page.getByRole('button', { name: 'Add "Air ticket" only for this invoice' }).click()
  await lines.getByLabel('Unit price').fill('45000')
  // Nothing more is asked until the line says what it is for.
  await expect(first.getByLabel('PNR number')).toHaveCount(0)
  await first.getByLabel('This line is for').selectOption('air_ticket')
  await expect(first.getByLabel('From', { exact: true })).toHaveValue('Dhaka')

  // Saved without its destination and dates: refused, and said beside the buttons.
  await page.getByRole('button', { name: 'Save invoice' }).click()
  await expect(first.getByText('Enter where the flight goes to.')).toBeVisible(FIRST_LOAD)
  await expect(first.getByText('Choose the departure or check-in date.')).toBeVisible()

  await first.getByLabel('To', { exact: true }).fill('Bangkok')
  await first.getByLabel('PNR number').fill(pnr)
  await first.getByLabel('Departure date').fill(inDays(5))
  await first.getByLabel('Return date').fill(inDays(12))

  await page.getByRole('button', { name: '+ Add New Item' }).click()
  const second = page.getByTestId('line-service').nth(1)
  await lines.getByLabel('Item').nth(1).fill('Hotel')
  await page.getByRole('button', { name: 'Add "Hotel" only for this invoice' }).click()
  await lines.getByLabel('Unit price').nth(1).fill('12000')
  await second.getByLabel('This line is for').selectOption('hotel_booking')
  await second.getByLabel('Hotel name').fill(hotel)
  await second.getByLabel('Room type').fill('Deluxe twin')
  await second.getByLabel('Reservation / confirmation no.').fill('RES-778')
  await second.getByLabel('Check-in date').fill(inDays(5))
  await second.getByLabel('Check-out date').fill(inDays(8))
  await second.getByLabel('Booking status').selectOption('confirmed')

  await page.getByRole('button', { name: 'Save invoice' }).click()
  await expect(page).toHaveURL(/\/invoices$/, FIRST_LOAD)

  // The ticket, with the next departures first and this one flagged.
  await page.goto('/air-ticketing')
  await expect(page.getByRole('tab', { name: 'Tickets' })).toHaveAttribute('aria-selected', 'true', FIRST_LOAD)
  const row = page.getByTestId('air-services-table').locator('tbody tr').filter({ hasText: pnr })
  await expect(row).toContainText('Dhaka → Bangkok', FIRST_LOAD)
  await expect(row.getByTestId('departure-soon')).toContainText(/Departure soon · \d days? left/)
  await expect(row.getByTestId('service-status')).toContainText('Booked')

  const whatsapp = () => row.getByRole('link', { name: `WhatsApp — ${name}` }).getAttribute('href')
  const before = decodeURIComponent((await whatsapp()) ?? '')
  expect(before).toMatch(/^https:\/\/wa\.me\/88017/)
  expect(before).toContain(`PNR: ${pnr}`)

  // The schedule changes: it needs its note, and the customer's message then carries it.
  await row.getByRole('button', { name: `Update status — ${name}` }).click()
  const form = page.getByTestId('service-status-form')
  await form.getByLabel('Ticket status').selectOption('schedule_changed')
  await form.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(form.getByText('Write what the new schedule is.')).toBeVisible(FIRST_LOAD)
  await form.getByLabel('New schedule').fill('BG 388 now leaves at 22:40')
  await form.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(form).toBeHidden(FIRST_LOAD)
  await expect(row.getByTestId('service-status')).toContainText('Schedule changed', FIRST_LOAD)
  await expect(row.getByTestId('service-status')).toContainText('BG 388 now leaves at 22:40')
  await expect.poll(async () => decodeURIComponent((await whatsapp()) ?? '')).toContain('BG 388 now leaves at 22:40')
  const after = decodeURIComponent((await whatsapp()) ?? '')
  expect(after).toContain(pnr)
  expect(after).toContain('Dhaka → Bangkok')
  await page.getByRole('radio', { name: /^Schedule changed \(\d+\)$/ }).click()
  await expect(row).toBeVisible(FIRST_LOAD)

  // The website's enquiries are a tab away, and back.
  await page.getByRole('tab', { name: 'Enquiries' }).click()
  await expect(page).toHaveURL(/\/air-ticketing\?state=open$/)
  await expect(page.getByRole('tab', { name: 'Enquiries' })).toHaveAttribute('aria-selected', 'true')
  await page.getByRole('tab', { name: 'Tickets' }).click()
  await expect(page).toHaveURL(/\/air-ticketing$/)

  // The hotel, in its own list.
  await page.goto('/hotel-requests')
  const stay = page.getByTestId('hotel-services-table').locator('tbody tr').filter({ hasText: hotel })
  await expect(stay).toContainText('RES-778', FIRST_LOAD)
  await expect(stay.getByTestId('service-status')).toContainText('Confirmed')

  // The invoice shows the same record, and changes it.
  await page.goto(`/invoices?search=${encodeURIComponent(name)}`)
  await page.getByRole('button', { name: /^#INV-\d+$/ }).first().click()
  const details = page.getByRole('dialog', { name: 'Invoice details' })
  const service = details.getByTestId('invoice-line-service').first()
  await expect(service).toContainText(`PNR ${pnr}`, FIRST_LOAD)
  await expect(service).toContainText('Schedule changed')
  await service.getByRole('button', { name: 'Update status' }).click()
  await form.getByLabel('Ticket status').selectOption('confirmed')
  await form.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(form).toBeHidden(FIRST_LOAD)
  await expect(details.getByTestId('invoice-details')).toContainText(`PNR ${pnr}`)
  await expect(service).toContainText('Confirmed', FIRST_LOAD)

  await page.goto('/air-ticketing')
  await expect(row.getByTestId('service-status')).toContainText('Confirmed', FIRST_LOAD)
})
