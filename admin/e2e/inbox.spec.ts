import { expect, test, type APIRequestContext } from '@playwright/test'

import { E2E_WASENDER_SECRET } from '../../scripts/e2e-api.mjs'
import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/admin-inbox.md: a customer's WhatsApp messages (WaSender's webhook) open a chat in Communication → Inbox, counted
 * on the sidebar; staff read it, answer with a quick reply, turn it into a lead and start a booking. WaSender and Meta
 * point at a closed local port in e2e, so the reply stays "sending" and nothing leaves the machine.
 */
const phone = `8801${7 + (Date.now() % 3)}${String(Date.now()).slice(-8)}`

async function whatsApp(request: APIRequestContext, id: string, text: string) {
  const response = await request.post(`${API_URL}/api/v1/webhooks/wasender`, {
    headers: { 'X-Webhook-Signature': E2E_WASENDER_SECRET, Accept: 'application/json' },
    data: {
      event: 'messages.received',
      timestamp: Math.floor(Date.now() / 1000),
      data: { messages: { key: { id, fromMe: false, remoteJid: `${phone}@s.whatsapp.net`, cleanedSenderPn: phone }, pushName: 'Rahat Khan', messageBody: text, message: { conversation: text } } },
    },
  })
  expect(response.status(), await response.text()).toBe(200)
}

test('a WhatsApp chat is read, answered with a quick reply, turned into a lead and a booking', async ({ page }) => {
  await whatsApp(page.request, `E2E-${phone}-1`, 'Assalamu alaikum, Kashmir group tour ache?')
  await whatsApp(page.request, `E2E-${phone}-2`, 'Amra 6 jon')

  await signIn(page, 'sales_agent')
  await expect(page.getByTestId('nav-badge-inbox')).toHaveText('1', FIRST_LOAD)
  await page.getByRole('navigation').getByRole('link', { name: /Inbox/ }).click()

  const chat = page.getByRole('button', { name: /Rahat Khan/ })
  await expect(chat).toContainText('Amra 6 jon', FIRST_LOAD)
  await expect(chat).toContainText('WhatsApp')
  await chat.click()

  const thread = page.getByTestId('thread')
  await expect(thread.getByTestId('bubble')).toHaveCount(2, FIRST_LOAD)
  await expect(thread).toContainText('Kashmir group tour ache?')
  // Opening it reads it: the badge goes.
  await expect(page.getByTestId('nav-badge-inbox')).toHaveCount(0, FIRST_LOAD)

  // A quick reply fills the box; staff add to it and send.
  await page.getByRole('list', { name: 'Quick replies' }).getByRole('listitem').filter({ hasText: 'Greeting' }).click()
  const box = page.getByRole('textbox', { name: 'Type a reply…' })
  await expect(box).toHaveValue(/ভবঘুরে হলিডেজে যোগাযোগের জন্য ধন্যবাদ/)
  await box.press('End')
  await box.pressSequentially(' 6 jon-er jonno details pathacchi.')
  await box.press('Enter')
  const sent = thread.locator('[data-testid="bubble"][data-direction="out"]')
  await expect(sent).toContainText('6 jon-er jonno details pathacchi.', FIRST_LOAD)
  await expect(box).toHaveValue('')
  // The one who answered takes the chat.
  await expect(page.getByRole('button', { name: 'Let it go' })).toBeVisible()

  // A lead from the chat, with the WhatsApp number; then a booking for them.
  const panel = page.getByTestId('customer-panel')
  await expect(panel.getByLabel('Name')).toHaveValue('Rahat Khan')
  await expect(panel.getByLabel('Mobile number')).toHaveValue(phone.replace(/^88/, ''))
  await panel.getByRole('button', { name: 'Create lead' }).click()
  await expect(panel.getByRole('link', { name: 'Rahat Khan' })).toBeVisible(FIRST_LOAD)
  await expect(panel).toContainText('No bookings yet.')
  await panel.getByRole('link', { name: 'Start booking' }).click()
  await expect(page).toHaveURL(/\/bookings\/new$/)
  await expect(page.locator('main')).toContainText('Rahat Khan', FIRST_LOAD)

  // Closing moves it out of the open list; a new message opens it again — read at once, since it is on screen.
  await page.goBack()
  await page.getByRole('button', { name: 'Close', exact: true }).click()
  await expect(page.getByRole('tab', { name: /Open/ })).toContainText('0', FIRST_LOAD)
  await whatsApp(page.request, `E2E-${phone}-3`, 'Price koto porbe?')
  await expect(thread).toContainText('Price koto porbe?', FIRST_LOAD)
  await expect(page.getByRole('button', { name: 'Close', exact: true })).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('tab', { name: /Open/ })).toContainText('1', FIRST_LOAD)
})

test('an agent starts a WhatsApp chat with a typed number, and the same number opens that chat again', async ({ page }) => {
  // Client, 2026-10-01 (docs/admin-inbox.md §8). WaSender can't be reached here, so the number's check is skipped.
  const typed = `013${String(Date.now()).slice(-8)}`
  await signIn(page, 'sales_agent')
  await page.goto('/inbox')

  // A number with no chat, searched for, offers to start one.
  await page.getByRole('searchbox', { name: 'Search name, number or message' }).fill(typed)
  await page.getByRole('button', { name: `Start a chat with +88${typed}` }).click(FIRST_LOAD)
  const dialog = page.getByTestId('new-chat')
  await expect(dialog.getByLabel('WhatsApp number')).toHaveValue(typed)
  await expect(dialog).toContainText(`Chat with +88${typed}`)
  await dialog.getByLabel('Name (optional)').fill('Sadia Afrin')
  await dialog.getByLabel('First message (optional)').fill('Assalamu alaikum, Bhabaghure Holidays theke bolchi.')
  await dialog.getByRole('button', { name: 'Start chat and send' }).click()

  // It opens as the agent's chat, at the top of the open list, with the first message going out.
  await expect(page).toHaveURL(/\/inbox\/\d+$/, FIRST_LOAD)
  await expect(page.getByText('Chat started')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Sadia Afrin' })).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('thread').locator('[data-testid="bubble"][data-direction="out"]')).toContainText('Bhabaghure Holidays theke bolchi.')
  await expect(page.getByRole('button', { name: 'Let it go' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Conversations' }).getByRole('listitem').first()).toContainText('Sadia Afrin', FIRST_LOAD)

  // The same number, written another way, opens that chat instead of a second one.
  await page.getByRole('button', { name: '+ New chat' }).click()
  await dialog.getByLabel('WhatsApp number').fill(`+88 ${typed.slice(0, 5)}-${typed.slice(5)}`)
  await dialog.getByRole('button', { name: 'Start chat', exact: true }).click()
  await expect(page.getByText('This number already has a chat: opened it')).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('button', { name: /Sadia Afrin/ })).toHaveCount(1)

  // A foreign number without its country code can't be told from a typo.
  await page.getByRole('button', { name: '+ New chat' }).click()
  await dialog.getByLabel('WhatsApp number').fill('9812345678')
  await dialog.getByRole('button', { name: 'Start chat', exact: true }).click()
  await expect(dialog.getByRole('alert')).toContainText('Enter a WhatsApp number', FIRST_LOAD)
})

test('an admin manages quick replies, and a Page token Facebook refuses is not saved', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/inbox/settings')
  await expect(page.getByRole('heading', { name: 'WhatsApp (main number)' })).toBeVisible(FIRST_LOAD)
  await expect(page.getByText('/api/v1/webhooks/wasender')).toBeVisible()
  await expect(page.getByTestId('messenger-steps').getByRole('listitem')).toHaveCount(5)

  await page.getByRole('button', { name: '+ Add' }).click()
  await page.getByLabel('Button name').fill('Office address')
  await page.getByLabel('Text').fill('Our office is open 10 am to 7 pm, Saturday to Thursday.')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByText('Our office is open 10 am to 7 pm, Saturday to Thursday.')).toBeVisible(FIRST_LOAD)

  await page.getByLabel('Page access token').fill('EAAG-not-a-real-token-000000')
  await page.getByLabel('App secret').fill('not-a-real-app-secret')
  await page.getByRole('button', { name: 'Connect the Page' }).click()
  await expect(page.getByText('Facebook didn’t accept this Page access token. Copy it again from the Meta app.')).toBeVisible({ timeout: 45_000 })
  await expect(page.getByText('Not connected')).toBeVisible()

  // Sales agents don't get the settings.
  await signIn(page, 'sales_agent')
  await page.goto('/inbox')
  await expect(page.getByRole('link', { name: 'Settings' })).toHaveCount(0, FIRST_LOAD)
})
