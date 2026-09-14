import { expect, test } from '@playwright/test'

import { lastFakeWhatsApp, signIn, websiteBooking } from './helpers'

/**
 * docs/phase-4-whatsapp.md: the published notifications number, a staff member verifying their own WhatsApp number,
 * templates with the server's preview, alert recipients, and WhatsApp messages on a booking. WhatsApp goes through the
 * fake gateway (scripts/e2e-api.mjs); these tests run in order and build on each other.
 */
test.describe.configure({ mode: 'serial' })

const SENDER_LINE = 'ভবঘুরে হলিডেজ · Bhabaghure Holidays'
const STAFF_WHATSAPP = '+8801811000222'

test('the notifications number is published in site settings and must differ from the main line', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  const contact = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Contact' }) })
  const field = contact.getByLabel('Notifications WhatsApp number')

  await field.fill('+8801743939300')
  await contact.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(contact.getByRole('alert')).toContainText('must be different')

  await field.fill('+8801911000111')
  await contact.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(contact.getByRole('button', { name: 'Saved' })).toBeVisible()
})

test('a staff member verifies their own WhatsApp number with the code sent to it', async ({ page }) => {
  await signIn(page, 'admin')
  await page.getByRole('link', { name: 'E2E admin' }).click()
  await expect(page).toHaveURL(/\/profile$/)
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'My WhatsApp number' }) })

  await card.getByLabel('WhatsApp number', { exact: true }).fill('01811-000222')
  await card.getByRole('button', { name: 'Send code' }).click()
  await expect(page.getByText('Code sent on WhatsApp')).toBeVisible()
  const sent = lastFakeWhatsApp(STAFF_WHATSAPP)
  expect(sent).toContain(SENDER_LINE)
  const code = /\b(\d{6})\b/.exec(sent)![1]

  await card.getByLabel(/^Code sent to/).fill(code === '000000' ? '111111' : '000000')
  await card.getByRole('button', { name: 'Verify', exact: true }).click()
  await expect(card.getByText('That code isn’t right.')).toBeVisible()

  await card.getByLabel(/^Code sent to/).fill(code)
  await card.getByRole('button', { name: 'Verify', exact: true }).click()
  await expect(page.getByText('WhatsApp number verified')).toBeVisible()
  await expect(card.getByText('Verified', { exact: true })).toBeVisible()
})

test('templates insert variables, preview exactly what is sent, and refuse variables the event lacks; alerts go to verified staff', async ({ page }) => {
  await websiteBooking(page, 'Preview Sample')
  await signIn(page, 'admin')
  await page.getByRole('navigation').getByRole('link', { name: /Notifications/ }).click()
  await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible()
  await expect(page.getByText('Test gateway — nothing leaves the server').first()).toBeVisible()

  await page.getByRole('button', { name: /^Payment received/ }).click()
  const editor = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Payment received', level: 2 }) })
  await editor.getByRole('radio', { name: 'English' }).click()
  const body = editor.getByLabel('WhatsApp message')
  await body.fill('Dear {{name}}, we received ')
  await editor.getByRole('button', { name: '{{amount}}' }).click()
  await expect(body).toHaveValue('Dear {{name}}, we received {{amount}}')
  const preview = editor.getByTestId('template-preview')
  await expect(preview).toContainText(SENDER_LINE)
  await expect(preview).toContainText('Dear Preview Sample, we received BDT')

  await body.fill('Dear {{name}}, {{seats}} seats')
  await expect(editor.getByText('Not available in this message: {{seats}}')).toBeVisible()
  await editor.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(editor.getByText('This template can’t use {{seats}}.')).toBeVisible()

  await body.fill('Dear {{name}}, we received {{amount}}. Balance {{due}}.')
  await editor.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(editor.getByRole('button', { name: 'Saved' })).toBeVisible()

  await page.getByRole('radio', { name: 'Sales alerts' }).click()
  const newBooking = page.locator('fieldset').filter({ hasText: 'New booking alert' })
  await newBooking.getByRole('checkbox', { name: /E2E admin/ }).check()
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Saved' })).toBeVisible()
})

test('a booking shows its messages; staff send a WhatsApp to the number on record and can turn WhatsApp off for the customer', async ({ page }) => {
  const { reference, phone } = await websiteBooking(page, 'Sadia Rahman', 'sadia@example.test')
  // The admin is on the new-booking alert list with a verified number.
  expect(lastFakeWhatsApp(STAFF_WHATSAPP)).toContain(reference)
  expect(lastFakeWhatsApp(`+88${phone}`)).toContain(SENDER_LINE)

  await signIn(page, 'admin')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference }).click()
  const messages = page.locator('section').filter({ has: page.getByRole('heading', { name: 'WhatsApp, email & SMS' }) })
  const rows = messages.getByTestId('notification-group')
  const received = rows.filter({ hasText: 'Booking received' })
  await expect(received.getByTestId('channel-whatsapp')).toContainText('Sent')
  await expect(received.getByTestId('channel-email')).toContainText('Sent')
  await expect(received.getByTestId('channel-sms')).toContainText('Not used')

  await messages.getByRole('button', { name: 'Send WhatsApp' }).click()
  const dialog = page.getByRole('dialog', { name: 'Send WhatsApp' })
  // There is nowhere to type a number: the message is the only field.
  await expect(dialog.getByRole('textbox')).toHaveCount(1)
  await dialog.getByLabel('Message').fill('Your visa appointment is on Sunday at 10:00.')
  await expect(dialog.getByTestId('whatsapp-preview')).toContainText(`${SENDER_LINE}\nYour visa appointment is on Sunday at 10:00.`)
  await dialog.getByRole('button', { name: 'Send', exact: true }).click()
  await expect(page.getByText('WhatsApp message sent')).toBeVisible()
  await expect(rows.filter({ hasText: 'Message from staff' }).getByTestId('channel-whatsapp')).toContainText('Sent')
  expect(lastFakeWhatsApp(`+88${phone}`)).toContain(`${SENDER_LINE}\nYour visa appointment is on Sunday at 10:00.`)

  await messages.getByRole('switch', { name: /WhatsApp messages to this customer/ }).click()
  await expect(page.getByText('WhatsApp messages turned off for this customer')).toBeVisible()
  await expect(messages.getByRole('button', { name: 'Send WhatsApp' })).toHaveCount(0)
  await expect(messages.getByText('This customer turned automated WhatsApp messages off.')).toBeVisible()

  await page.goto('/notifications?tab=log')
  await expect(page.getByTestId('notification-row').filter({ hasText: 'New booking alert' }).first()).toContainText('Open booking')
  // What each channel costs this month; WhatsApp and email messages cost nothing per message.
  await expect(page.getByTestId('cost-summary')).toContainText('SMS')
  await expect(page.getByTestId('notification-row').filter({ hasText: 'Message from staff' }).first().getByTestId('notification-cost')).toHaveText('BDT 0')
})

test('the SMS template shows its part count and estimated cost, and warns above three parts', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/notifications')
  await expect(page.getByTestId('sms-connection')).toContainText('Test gateway')

  await page.getByRole('button', { name: /^Payment received/ }).click()
  const editor = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Payment received', level: 2 }) })
  await editor.getByRole('radio', { name: 'SMS' }).click()
  await editor.getByRole('radio', { name: 'বাংলা' }).click()
  const estimate = editor.getByTestId('sms-estimate')
  await expect(estimate).toContainText('Unicode / Bangla')
  await expect(estimate).toContainText(/\d parts? · .* · about BDT 0\.\d\d a message|about BDT \d/)
  await expect(estimate.getByRole('alert')).toHaveCount(0)

  await editor.getByLabel('SMS text').fill('ক'.repeat(250))
  await expect(estimate).toContainText('4 parts')
  await expect(estimate).toContainText('BDT 1.40')
  await expect(estimate.getByRole('alert')).toContainText('Longer than 3 parts')
})

test('only notification managers open the notifications screen', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await expect(page.getByRole('navigation').getByRole('link', { name: /Notifications/ })).toHaveCount(0)
  await page.goto('/notifications')
  await expect(page.getByText("You don't have access to this screen")).toBeVisible()
})
