import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, PHOTO, signIn, websiteBooking } from './helpers'

/**
 * docs/phase-6-customer-portal.md §3.3, §3.5, §3.7 from the admin side: the Documents queue (open, verify, reject with
 * a reason), the Support queue (overdue badge, reply by WhatsApp and email, close), and the portal controls on a customer.
 */

/** A photo uploaded the way the portal uploads one: encrypted on the private disk, waiting for review. */
function portalUpload(reference: string): void {
  artisan(
    'tinker',
    `--execute=$b = App\\Models\\Booking::query()->with(['travellers', 'customer'])->where('reference', '${reference}')->firstOrFail(); app(App\\Services\\Documents\\TravellerDocuments::class)->upload($b->travellers->first(), 'photo', new Illuminate\\Http\\UploadedFile('${PHOTO.replace(/\\/g, '/')}', 'photo.jpg', 'image/jpeg', null, true), $b->customer); echo 'ok';`,
  )
}

test('a portal photo is opened, refused to an agent who does not own the trip, rejected with a reason by an admin, and shown on the booking', async ({ page }) => {
  const name = `Docs Traveller ${String(Date.now()).slice(-4)}`
  const { reference } = await websiteBooking(page, name)
  portalUpload(reference)

  await signIn(page, 'sales_agent')
  await page.goto('/documents')
  const agentRow = page.getByTestId('document-reviews-table').locator('tbody tr').filter({ hasText: name })
  await expect(agentRow).toContainText('Photo · from the portal')
  await expect(agentRow.getByRole('button', { name: `Verify — ${name} · Photo (Only someone who can edit this booking can review it)` })).toBeDisabled()

  await signIn(page, 'admin')
  const badge = page.getByTestId('nav-badge-documents')
  await expect(badge).toBeVisible()
  const count = Number(await badge.textContent())
  await badge.click()
  await expect(page).toHaveURL(/\/documents\?status=uploaded$/)
  const row = page.getByTestId('document-reviews-table').locator('tbody tr').filter({ hasText: name })

  // The file opens decrypted in a new tab.
  const popup = page.waitForEvent('popup')
  await row.getByRole('button', { name: `Open file — ${name} · Photo` }).click()
  await expect.poll(async () => (await popup).url()).toMatch(/^blob:/)
  await (await popup).close()

  await row.getByRole('button', { name: `Reject — ${name} · Photo` }).click()
  const dialog = page.getByRole('dialog', { name: `Reject ${name}’s upload` })
  await dialog.getByLabel('What needs to change').fill('Face not visible — plain background please')
  await dialog.getByRole('button', { name: 'Reject' }).click()
  await expect(page.getByText('Rejected — the customer will see why')).toBeVisible()
  if (count === 1) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(count - 1))

  await page.getByRole('radio', { name: 'Rejected', exact: true }).click()
  await expect(page.getByTestId('document-reviews-table').locator('tbody tr').filter({ hasText: name })).toContainText('Face not visible — plain background please')
  await page.getByTestId('document-reviews-table').locator('tbody tr').filter({ hasText: name }).getByRole('link', { name: `Open booking — ${name} · Photo` }).click()
  const travellers = page.locator('[data-testid^="traveller-documents-"]').first()
  await expect(travellers).toContainText('Photo · Rejected')
  // Nepal gives the visa on arrival: visa and insurance start as not needed, and staff can still set either.
  await expect(travellers).toContainText('Visa · Not needed')
  await expect(travellers).toContainText('Insurance · Not needed')

  // Visa set from the booking: the customer sees the note in the portal.
  await travellers.getByRole('button', { name: `Set Visa — ${name}` }).click()
  const visa = page.getByRole('dialog', { name: `Visa for ${name}` })
  await visa.getByLabel('Status').selectOption('issued')
  await visa.getByLabel('Note for the customer').fill('Nepal — visa on arrival')
  await visa.getByRole('button', { name: 'Save' }).click()
  await expect(travellers).toContainText('Visa · Issued')
})

test('an overdue support ticket is counted, answered by WhatsApp and email, and closed; portal sign-in can be turned off', async ({ page }) => {
  const name = `Support Customer ${String(Date.now()).slice(-4)}`
  const { reference } = await websiteBooking(page, name)
  const subject = `Room change for ${name}`
  artisan(
    'tinker',
    `--execute=$b = App\\Models\\Booking::query()->with('customer')->where('reference', '${reference}')->firstOrFail(); $t = app(App\\Services\\Support\\SupportDesk::class)->open($b->customer, $b, '${subject}', 'Twin to double, please.'); $t->forceFill(['last_customer_message_at' => now()->subHours(30)])->save(); echo 'ok';`,
  )

  await signIn(page, 'admin')
  const badge = page.getByTestId('nav-badge-support')
  await expect(badge).toBeVisible()
  const count = Number(await badge.textContent())
  await badge.click()
  await expect(page).toHaveURL(/\/support\?status=open&overdue=1$/)
  const row = page.getByTestId('support-tickets-table').locator('tbody tr').filter({ hasText: subject })
  await expect(row).toContainText('Over 24 hours')
  await expect(row).toContainText(reference)

  await row.getByRole('link', { name: /^Open ticket — ST-\d{4}$/ }).click()
  await expect(page.getByTestId('support-messages')).toContainText('Twin to double, please.')
  await page.getByLabel('Reply to the customer').fill('Done — a double room, no extra charge.')
  await page.getByRole('button', { name: 'Send reply' }).click()
  await expect(page.getByText('Reply sent')).toBeVisible()
  await expect(page.getByTestId('support-messages')).toContainText('Done — a double room, no extra charge.')
  // Delivery by WhatsApp and email is planned by the API (SupportTicketsTest); here the ticket is now the customer's turn.
  await expect(page.getByText('Answered', { exact: true })).toBeVisible()
  if (count === 1) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(count - 1))

  await page.getByRole('button', { name: 'Close ticket' }).click()
  await expect(page.getByText('Ticket closed')).toBeVisible()

  // The customer's portal card: not signed in yet; sign-in turned off and back on.
  await page.getByRole('link', { name }).first().click()
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Customer portal' }) })
  await expect(card).toContainText('Not signed in yet')
  await card.getByRole('button', { name: 'Turn sign-in off' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Turn sign-in off' }).click()
  await expect(page.getByText('Portal sign-in turned off')).toBeVisible()
  await expect(card).toContainText('Sign-in turned off')
  await card.getByRole('button', { name: 'Turn sign-in back on' }).click()
  await expect(page.getByText('Portal sign-in turned back on')).toBeVisible()
})

test('an e-ticket is recorded on a booking with its file, opened, and voided with a reason that stays on the booking', async ({ page }) => {
  const name = `Ticket Traveller ${String(Date.now()).slice(-4)}`
  const { reference } = await websiteBooking(page, name)

  await signIn(page, 'admin')
  await page.goto('/bookings')
  await page.getByRole('link', { name: reference, exact: true }).click({ timeout: FIRST_LOAD.timeout })
  const tickets = page.locator('section').filter({ has: page.getByRole('heading', { name: 'E-tickets' }) })
  await expect(tickets).toContainText('No e-tickets recorded yet.', FIRST_LOAD)

  await tickets.getByRole('button', { name: '+ E-ticket' }).click()
  const dialog = page.getByRole('dialog', { name: 'Record an e-ticket' })
  await expect(dialog.getByLabel('Traveller')).toHaveValue(/\d+/)
  await dialog.getByLabel('Airline').fill('Biman Bangladesh')
  await dialog.getByLabel('PNR').fill('k7xq2m')
  await expect(dialog.getByLabel('PNR')).toHaveValue('K7XQ2M')
  await dialog.getByLabel('Ticket number').fill('057-2841993012')
  await dialog.getByLabel('Route').fill('DAC–KTM–DAC')
  await dialog.getByLabel('E-ticket file (optional)').setInputFiles(PHOTO)
  await dialog.getByRole('button', { name: 'Save e-ticket' }).click()
  await expect(page.getByText('E-ticket recorded — the customer sees it in the portal')).toBeVisible()

  const row = tickets.getByTestId('booking-tickets').locator('li').filter({ hasText: '057-2841993012' })
  await expect(row).toContainText(name)
  await expect(row).toContainText('Issued')
  await expect(row).toContainText('Biman Bangladesh · PNR K7XQ2M · 057-2841993012 · DAC–KTM–DAC')

  // The file is private: it opens decrypted in a new tab.
  const popup = page.waitForEvent('popup')
  await row.getByRole('button', { name: 'Open e-ticket 057-2841993012' }).click()
  await expect.poll(async () => (await popup).url()).toMatch(/^blob:/)
  await (await popup).close()

  await row.getByRole('button', { name: 'Void e-ticket 057-2841993012' }).click()
  const voiding = page.getByRole('dialog', { name: 'Void e-ticket 057-2841993012?' })
  await expect(voiding.getByRole('button', { name: 'Void', exact: true })).toBeDisabled()
  await voiding.getByLabel('Why it is void').fill('Surname misspelt — reissued')
  await voiding.getByRole('button', { name: 'Void', exact: true }).click()
  await expect(page.getByText('E-ticket voided')).toBeVisible()
  await expect(row).toContainText('Void')
  await expect(row).toContainText(/Voided by .+: Surname misspelt — reissued/)
  await expect(row.getByRole('button', { name: 'Void e-ticket 057-2841993012' })).toHaveCount(0)
})
