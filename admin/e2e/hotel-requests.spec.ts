import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { API_URL, signIn } from './helpers'

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.B: a hotel quotation request from the website waits in Hotel requests,
 * flagged after 24 hours and counted by the badge; a sales agent sends the quotation as a reply (WhatsApp and email,
 * logged with each channel's status) and marks it quoted in the same step.
 */
test('a hotel request waiting over 24 hours is answered with a reply that marks it quoted; the badge and the log follow', async ({ page }) => {
  const phone = `0181${String(Date.now()).slice(-7)}`
  const name = `Hotel Guest ${phone.slice(-4)}`
  const checkIn = new Date(Date.now() + 40 * 86_400_000).toISOString().slice(0, 10)
  const checkOut = new Date(Date.now() + 43 * 86_400_000).toISOString().slice(0, 10)
  const sent = await page.request.post(`${API_URL}/api/v1/public/hotel-quotes`, {
    headers: { Accept: 'application/json' },
    data: { location: "Cox's Bazar", check_in: checkIn, check_out: checkOut, hotel_category: '5', guests: 2, note: 'Honeymoon, sea view', name, phone, email: 'hotel.guest@example.test', locale: 'en' },
  })
  expect(sent.status()).toBe(202)
  // It arrived 26 hours ago.
  artisan('tinker', `--execute=App\\Models\\Inquiry::query()->where('name', '${name}')->update(['created_at' => now()->subHours(26)]); echo 'ok';`)

  await signIn(page, 'sales_agent')
  const badge = page.getByTestId('nav-badge-hotel_inquiries')
  await expect(badge).toBeVisible()
  const count = Number(await badge.textContent())
  await badge.click()
  await expect(page).toHaveURL(/\/hotel-requests\?state=open&stale=1$/)
  await expect(page.getByRole('heading', { name: 'Hotel requests', level: 1 })).toBeVisible()

  const row = page.getByTestId('hotel-inquiries-table').locator('tbody tr').filter({ hasText: name })
  await expect(row.getByTestId('stale-flag')).toHaveText('Waiting 26 h')
  await expect(row).toContainText("Cox's Bazar")
  await expect(row).toContainText('3 nights · 2 guests · 5-star')
  await expect(row.getByText('Pool', { exact: true })).toBeVisible()

  await row.getByRole('button', { name: `Reply — ${name}` }).click()
  const dialog = page.getByRole('dialog', { name: `Hotel request from ${name}` })
  await expect(dialog).toContainText('Honeymoon, sea view')
  // No notifications number is published in the e2e data: WhatsApp is held, the email still goes.
  await expect(dialog.getByRole('note')).toContainText('WhatsApp can’t go out now')
  const send = dialog.getByRole('button', { name: 'Send reply' })
  await expect(send).toBeDisabled()
  await dialog.getByRole('textbox', { name: 'Message' }).fill('Sayeman Beach Resort, sea-view deluxe: ৳ 14,500 a night with breakfast.')
  // The preview is the WhatsApp as sent: sender line, the reply template filled for this request, the typed text.
  const preview = dialog.getByTestId('whatsapp-preview')
  await expect(preview).toContainText('Bhabaghure Holidays')
  await expect(preview).toContainText(`Dear ${name}, a reply to your Cox's Bazar hotel request: Sayeman Beach Resort`)
  await expect(dialog.getByLabel('Also mark it quoted')).toBeChecked()
  await send.click()

  await expect(page.getByText(`Reply sent to ${name}`)).toBeVisible()
  // The dialog stays open on the log: the reply, WhatsApp not sent, the email taken by the log mailer.
  const reply = dialog.getByTestId('notification-group').filter({ hasText: 'Reply to a quotation request' })
  await expect(reply.getByTestId('channel-whatsapp')).toContainText('Not sent')
  await expect(reply.getByTestId('channel-email')).toContainText('Not delivered')
  await expect(dialog).toContainText('Marked quoted by E2E sales_agent')
  await page.keyboard.press('Escape')

  // Out of the open queue, off the badge; in Quoted with its reply count.
  await expect(page.getByTestId('hotel-inquiries-table').locator('tbody tr').filter({ hasText: name })).toHaveCount(0)
  if (count === 1) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(count - 1))
  await page.getByRole('radio', { name: 'Quoted', exact: true }).click()
  const quoted = page.getByTestId('hotel-inquiries-table').locator('tbody tr').filter({ hasText: name })
  await expect(quoted.getByTestId('replies-count')).toHaveText('1 reply sent')
  await expect(quoted).toContainText('E2E sales_agent')
})
