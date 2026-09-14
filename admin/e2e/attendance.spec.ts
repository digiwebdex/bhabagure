import { expect, test, type Page } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5: a device added in the admin, its token used the way the office agent uses
 * it (check-in, device report, punches), a device user matched, a day corrected, a command picked up, and leave asked for
 * by a staff member and decided by an admin.
 */
test.describe.configure({ mode: 'serial' })

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

/** "2026-09-10" as the admin writes it in English: "10 September 2026". */
const label = (iso: string) => `${Number(iso.slice(8, 10))} ${MONTHS[Number(iso.slice(5, 7)) - 1]} ${iso.slice(0, 4)}`

/** A recent working day in Dhaka (not a Friday, the default day off). */
function recentWorkingDay(): string {
  const dhaka = (offsetDays: number) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date(Date.now() - offsetDays * 86_400_000))
  let offset = 3
  while (new Date(`${dhaka(offset)}T12:00:00Z`).getUTCDay() === 5) offset++
  return dhaka(offset)
}

async function asAgent(page: Page, token: string, path: string, data: unknown) {
  const response = await page.request.post(`${API_URL}/api/v1/attendance-agent/${path}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, data })
  expect(response.status(), `${path}: ${await response.text()}`).toBe(200)
  return ((await response.json()) as { data: Record<string, unknown> }).data
}

const deviceCard = (page: Page) => page.locator('section').filter({ has: page.getByRole('heading', { name: 'Attendance device' }) }).first()
let token = ''
let serial = ''

test('a device is added, the agent syncs with its token, a user is matched and a day is corrected', async ({ page }) => {
  const suffix = String(Date.now()).slice(-5)
  serial = `K40-E2E-${suffix}`
  const date = recentWorkingDay()

  await signIn(page, 'admin')
  await page.goto('/attendance')
  const card = deviceCard(page)
  await card.getByRole('button', { name: '+ Add device' }).click(FIRST_LOAD)
  const dialog = page.getByRole('dialog', { name: 'Add a device' })
  await dialog.getByLabel('Name').fill(`ZKTeco · E2E ${suffix}`)
  await dialog.getByRole('button', { name: 'Add and show the token' }).click()
  const tokenDialog = page.getByRole('dialog', { name: `Token for ZKTeco · E2E ${suffix}` })
  token = await tokenDialog.getByTestId('device-token').inputValue()
  expect(token).toMatch(/^bhatt_[0-9a-f]{64}$/)
  await tokenDialog.getByRole('button', { name: 'Done' }).click()
  await expect(card).toContainText('Waiting for the agent')

  // The office agent: check in, report the device and its users, send punches — twice, and the replay stores nothing.
  await asAgent(page, token, 'check-in', { agent_version: '1.0.0' })
  await asAgent(page, token, 'device', {
    status: 'ok', serial, model: 'K40/ID', firmware: 'Ver 6.60', address: '192.0.2.10:4370', device_time: `${date} 10:00:00`, pc_time: `${date} 10:00:00`,
    users_count: 1, fingers_count: 2, records_count: 2, users: [{ id: '41', name: 'E2E Agent' }],
  })
  const punches = [{ user_id: '41', time: `${date} 10:57:00` }, { user_id: '41', time: `${date} 17:30:00` }]
  expect(await asAgent(page, token, 'punches', { serial, punches })).toEqual({ stored: 2, duplicates: 0, rejected: [] })
  expect(await asAgent(page, token, 'punches', { serial, punches })).toEqual({ stored: 0, duplicates: 2, rejected: [] })

  await page.reload()
  await expect(card).toContainText('Connected', FIRST_LOAD)
  await expect(card).toContainText(serial)
  await expect(card.getByTestId('sync-log')).toContainText('2 punches sent: 0 new, 2 already here, 0 refused')

  // Device user 41 is the e2e sales agent.
  await card.getByRole('button', { name: 'Device users · 1 not matched' }).click()
  const users = page.getByRole('dialog', { name: `Users on ZKTeco · E2E ${suffix}` })
  await users.getByTestId('device-users').locator('li').filter({ hasText: 'ID 41' }).getByLabel('Staff member').selectOption({ label: 'E2E sales_agent · E2E-4' })
  await expect(page.getByText('Saved', { exact: true })).toBeVisible()
  await users.getByRole('button', { name: 'Done' }).click()

  // In 10:57, out 17:30: an early out. HR sets the out time with a reason: a full day, with the device's punches kept beside it.
  await page.goto(`/attendance?month=${date.slice(0, 7)}`)
  await page.getByTestId('attendance-month-table').locator('tbody tr').filter({ hasText: 'E2E sales_agent' }).getByRole('link', { name: 'Open days — E2E sales_agent' }).click(FIRST_LOAD)
  const correct = page.getByRole('button', { name: `Correct — ${label(date)}`, exact: true })
  const day = page.getByTestId('attendance-days-table').locator('tbody tr').filter({ has: correct })
  await expect(day).toContainText('Early out', FIRST_LOAD)
  await correct.click()
  const correction = page.getByRole('dialog', { name: `Correct ${label(date)}` })
  await expect(correction).toContainText('The device recorded: 10:57, 17:30')
  await correction.getByLabel('Change').selectOption('out')
  await correction.getByLabel('Time').fill('19:05')
  await correction.getByLabel('Why').fill('Airport pickup for the Nepal group; back after 19:00')
  await correction.getByRole('button', { name: 'Save correction' }).click()
  await expect(page.getByText('Correction saved')).toBeVisible()
  await expect(day).toContainText('Full day')
  await expect(day).toContainText('Set by hand · device: 10:57, 17:30')
  await expect(page.getByTestId('attendance-corrections')).toContainText('out set to 19:05')
})

test('Sync now waits for the office PC, which picks it up at its next check-in and reports it done', async ({ page }) => {
  test.skip(token === '', 'needs the device from the previous test')
  await signIn(page, 'admin')
  await page.goto('/attendance')
  const card = deviceCard(page)
  await card.getByRole('button', { name: 'Sync now' }).click(FIRST_LOAD)
  await expect(page.getByText('Sync now: the office PC picks it up within a minute')).toBeVisible()
  await expect(card).toContainText('Sync now is waiting for the office PC to check in.')

  expect((await asAgent(page, token, 'check-in', { agent_version: '1.0.0' })).command).toMatchObject({ type: 'pull' })
  await asAgent(page, token, 'device', { status: 'ok', serial, users: [], command: { type: 'pull', result: 'ok' } })

  await page.reload()
  await expect(card.getByTestId('sync-log')).toContainText('Sync now done', FIRST_LOAD)
  await expect(card).not.toContainText('waiting for the office PC')
})

test('a staff member asks for leave and an admin approves it as unpaid; the badge follows', async ({ page, browser }) => {
  const future = new Date(Date.now() + 40 * 86_400_000).toISOString().slice(0, 10)
  const reason = `Sister’s wedding ${String(Date.now()).slice(-4)}`

  const context = await browser.newContext()
  const staff = await context.newPage()
  await signIn(staff, 'sales_agent')
  await staff.goto('/my-attendance')
  await staff.getByRole('button', { name: '+ Ask for leave' }).click(FIRST_LOAD)
  const ask = staff.getByRole('dialog', { name: 'Ask for leave' })
  await ask.getByLabel('From').fill(future)
  await ask.getByLabel('To').fill(future)
  await ask.getByLabel('Reason').fill(reason)
  await ask.getByRole('button', { name: 'Send request' }).click()
  await expect(staff.getByText('Leave requested')).toBeVisible()
  await expect(staff.getByTestId('my-leave')).toContainText('Pending')
  await expect(staff.getByTestId('my-days-table')).toBeVisible()
  // A sales agent has My attendance, not the Attendance screen.
  await expect(staff.getByRole('navigation').getByRole('link', { name: '◴ My attendance' })).toBeVisible()
  await expect(staff.getByRole('navigation').getByRole('link', { name: '◷ Attendance' })).toHaveCount(0)

  await signIn(page, 'admin')
  const badge = page.getByTestId('nav-badge-leave_requests')
  await expect(badge).toBeVisible(FIRST_LOAD)
  const count = Number(await badge.textContent())
  await badge.click()
  await expect(page).toHaveURL(/\/attendance\?status=pending$/)
  await page.getByTestId('leave-requests-table').locator('tbody tr').filter({ hasText: reason }).getByRole('button', { name: /^Approve — E2E sales_agent/ }).click(FIRST_LOAD)
  const approve = page.getByRole('dialog', { name: 'Approve E2E sales_agent’s leave' })
  await approve.getByRole('switch', { name: /Paid leave/ }).click()
  await approve.getByRole('button', { name: 'Approve' }).click()
  await expect(page.getByText('E2E sales_agent’s leave approved')).toBeVisible()
  if (count === 1) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(count - 1))

  await staff.reload()
  await expect(staff.getByTestId('my-leave')).toContainText('Approved · unpaid', FIRST_LOAD)
  await context.close()
})
