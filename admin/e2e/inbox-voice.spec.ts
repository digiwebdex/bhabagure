import { expect, test, type APIRequestContext, type Page } from '@playwright/test'

import { E2E_WASENDER_SECRET } from '../../scripts/e2e-api.mjs'
import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/admin-inbox.md §9 and §10. Chrome's fake microphone records real audio in the browser, which is uploaded to the
 * real API. On Windows ffmpeg is a stand-in that copies the file (scripts/e2e-fake-ffmpeg.cmd), and WaSender points at a
 * closed local port, so a note stays "sending" and nothing leaves the machine.
 */
test.use({
  permissions: ['microphone'],
  launchOptions: { args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] },
})

const number = (end: number) => `8801${7 + (Date.now() % 3)}${String(Date.now()).slice(-7)}${end}`

async function whatsApp(request: APIRequestContext, phone: string, name: string, text: string) {
  const response = await request.post(`${API_URL}/api/v1/webhooks/wasender`, {
    headers: { 'X-Webhook-Signature': E2E_WASENDER_SECRET, Accept: 'application/json' },
    data: {
      event: 'messages.received',
      timestamp: Math.floor(Date.now() / 1000),
      data: { messages: { key: { id: `E2E-${phone}-${text.length}`, fromMe: false, remoteJid: `${phone}@s.whatsapp.net`, cleanedSenderPn: phone }, pushName: name, messageBody: text, message: { conversation: text } } },
    },
  })
  expect(response.status(), await response.text()).toBe(200)
}

/** inbox.spec.ts counts the open chats, so these tests leave none open. */
async function closeChat(page: Page, name: RegExp) {
  await page.getByRole('button', { name }).click()
  await page.getByRole('button', { name: 'Close', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Reopen' })).toBeVisible(FIRST_LOAD)
}

/** Keeps every microphone stream the page opens, to check it has been let go. */
const watchMicrophone = (page: Page) =>
  page.addInitScript(() => {
    const open = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices)
    const streams: MediaStream[] = []
    ;(window as unknown as { micStreams: MediaStream[] }).micStreams = streams
    navigator.mediaDevices.getUserMedia = async (constraints) => {
      const stream = await open(constraints)
      streams.push(stream)
      return stream
    }
  })

const micIsOff = (page: Page) =>
  page.evaluate(() => {
    const { micStreams } = window as unknown as { micStreams: MediaStream[] }
    return micStreams.length > 0 && micStreams.every((stream) => stream.getTracks().every((track) => track.readyState === 'ended'))
  })

test('a voice note is recorded, listened to and sent, and the microphone is let go', async ({ page }) => {
  const phone = number(1)
  await whatsApp(page.request, phone, 'Voice Customer', 'Ektu bujhiye bolben?')
  await watchMicrophone(page)
  await signIn(page, 'sales_agent')
  await page.goto('/inbox')
  await page.getByRole('button', { name: /Voice Customer/ }).click()
  const thread = page.getByTestId('thread')
  await expect(thread.getByTestId('bubble')).toHaveCount(1, FIRST_LOAD)

  // What is being typed survives a recording.
  const box = page.getByRole('textbox', { name: 'Type a reply…' })
  await box.fill('Half-written reply')

  // Cancel: nothing is sent, the microphone goes off, and the reply box is back.
  await page.getByRole('button', { name: 'Record a voice note' }).click()
  await expect(page.getByTestId('voice-timer')).toBeVisible(FIRST_LOAD)
  await page.getByRole('button', { name: 'Cancel' }).click()
  await expect(box).toHaveValue('Half-written reply')
  await expect.poll(() => micIsOff(page)).toBe(true)

  // Record: the timer runs, and Stop leads to listening, not sending.
  await page.getByRole('button', { name: 'Record a voice note' }).click()
  await expect(page.getByTestId('voice-timer')).toHaveText('0:02', { timeout: 15_000 })
  await page.getByRole('button', { name: 'Stop' }).click()
  await expect.poll(() => micIsOff(page)).toBe(true)
  const player = page.getByTestId('voice-player')
  await expect(player).toBeVisible()
  await expect.poll(() => player.evaluate((audio: HTMLAudioElement) => audio.readyState)).toBeGreaterThan(0)
  await expect(thread.getByTestId('bubble')).toHaveCount(1)

  // Delete takes it back to the reply box with nothing sent.
  await page.getByRole('button', { name: 'Delete' }).click()
  await expect(box).toHaveValue('Half-written reply')
  await expect(thread.getByTestId('bubble')).toHaveCount(1)

  // Record again and send: it shows in the thread as a player, and in the list as a voice message.
  await page.getByRole('button', { name: 'Record a voice note' }).click()
  await expect(page.getByTestId('voice-timer')).toHaveText('0:01', { timeout: 15_000 })
  await page.getByRole('button', { name: 'Stop' }).click()
  const sent = page.waitForResponse((response) => response.url().endsWith('/send-audio'), { timeout: 30_000 })
  await page.getByRole('button', { name: 'Send voice note' }).click()
  expect((await sent).status()).toBe(201)
  await expect(page.getByTestId('voice-preview')).toHaveCount(0)
  await expect(box).toHaveValue('Half-written reply')
  const bubble = thread.locator('[data-testid="bubble"][data-direction="out"]')
  await expect(bubble.locator('audio')).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('button', { name: /Voice Customer/ })).toContainText('🎤 Voice message', FIRST_LOAD)
  await closeChat(page, /Voice Customer/)
})

test('leaving the chat while recording lets the microphone go', async ({ page }) => {
  const phone = number(2)
  await whatsApp(page.request, phone, 'Second Customer', 'Hello')
  await watchMicrophone(page)
  await signIn(page, 'sales_agent')
  await page.goto('/inbox')
  await page.getByRole('button', { name: /Second Customer/ }).click()
  // Opening it reads it, before anything else happens here.
  await expect(page.getByTestId('nav-badge-inbox')).toHaveCount(0, { timeout: 30_000 })
  await page.getByRole('button', { name: 'Record a voice note' }).click()
  await expect(page.getByTestId('voice-timer')).toBeVisible(FIRST_LOAD)

  await page.getByRole('navigation').getByRole('link', { name: /Inbox/ }).first().click()
  await expect(page.getByTestId('voice-recorder')).toHaveCount(0)
  await expect.poll(() => micIsOff(page)).toBe(true)
  await closeChat(page, /Second Customer/)
})

test('a chat marked as unread comes back for the whole office and stays so on a colleague’s open screen', async ({ page, browser }) => {
  const phone = number(3)
  await whatsApp(page.request, phone, 'Follow Up', 'Pore janaben')
  await signIn(page, 'sales_agent')
  await expect(page.getByTestId('nav-badge-inbox')).toBeVisible(FIRST_LOAD)
  await page.goto('/inbox')
  const row = page.getByRole('button', { name: /Follow Up/ })
  await row.click()
  await expect(page.getByRole('heading', { name: 'Follow Up' })).toBeVisible(FIRST_LOAD)
  // Opening it read it: nothing is unread now.
  await expect(page.getByTestId('nav-badge-inbox')).toHaveCount(0, { timeout: 30_000 })

  // A colleague has the same chat open on their screen.
  const colleagueContext = await browser.newContext({ baseURL: 'http://localhost:5174' })
  const colleague = await colleagueContext.newPage()
  await signIn(colleague, 'admin')
  await colleague.goto(new URL(page.url()).pathname)
  await expect(colleague.getByRole('heading', { name: 'Follow Up' })).toBeVisible(FIRST_LOAD)

  // Mark as unread: back to the list, with the chat bold and counted.
  await page.getByRole('button', { name: 'Mark as unread' }).click()
  await expect(page).toHaveURL(/\/inbox$/, FIRST_LOAD)
  await expect(page.getByText('Pick a conversation to read and reply.')).toBeVisible()
  await expect(row.getByLabel('1 unread message')).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('nav-badge-inbox')).toHaveText('1', FIRST_LOAD)

  // It's under the Unread filter.
  await page.getByRole('tab', { name: /Unread/ }).click()
  await expect(page.getByRole('button', { name: /Follow Up/ })).toBeVisible(FIRST_LOAD)

  // The colleague sees it too (their list refreshes every 10 seconds), and their open chat refreshing every 5 must not
  // clear it.
  await expect(colleague.getByRole('button', { name: /Follow Up/ }).getByLabel('1 unread message')).toBeVisible({ timeout: 30_000 })
  await colleague.waitForTimeout(7_000)
  await page.reload()
  await expect(page.getByTestId('nav-badge-inbox')).toHaveText('1', FIRST_LOAD)
  await colleagueContext.close()

  // Opening it again clears it, as with any unread chat.
  await page.goto('/inbox')
  await page.getByRole('button', { name: /Follow Up/ }).click()
  await expect(page.getByTestId('nav-badge-inbox')).toHaveCount(0, { timeout: 30_000 })
  await closeChat(page, /Follow Up/)
})
