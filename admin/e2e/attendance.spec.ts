import { expect, test, type Page } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn, staffApi } from './helpers'

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5: a device added in the admin, its token used the way the office agent uses
 * it (check-in, device report, punches), a device user matched, a day corrected, a command picked up, the device address
 * set on the card and handed to the agent, the agent downloaded, and leave asked for by a staff member and decided by an
 * admin. Someone who hasn't signed in yet counts; someone switched off the device leaves attendance (client, 2026-10-03).
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
/** The card's state badge: "Connected" is also in its install steps, so the card's whole text proves nothing. */
const deviceState = (page: Page) => deviceCard(page).getByTestId('device-state')
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
  // Installing is a download and a double-click: nobody types PowerShell on the office PC. The address comes first, and
  // Windows' SmartScreen warning on the downloaded file is explained.
  await expect(tokenDialog).toContainText('Set the device’s address on its card first.')
  await expect(tokenDialog).toContainText('double-click install.cmd (if Windows says “Windows protected your PC”, press More info, then Run anyway')
  await expect(tokenDialog).toContainText('paste this token when it asks')
  await expect(tokenDialog).not.toContainText('PowerShell')
  await expect(tokenDialog.getByRole('button', { name: 'Download the agent' })).toBeVisible()
  await tokenDialog.getByRole('button', { name: 'Done' }).click()
  await expect(deviceState(page)).toHaveText('Waiting for the agent')

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
  await expect(deviceState(page)).toHaveText('Connected', FIRST_LOAD)
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

test('the device address is kept on the card, private addresses only, and reaches the agent at its next check-in', async ({ page }) => {
  test.skip(token === '', 'needs the device from the first test')
  // Nothing set on the card yet: the agent keeps to the address in its own agent.ini.
  expect((await asAgent(page, token, 'check-in', { agent_version: '1.1.0' })).device).toEqual({ address: null, port: 4370 })

  await signIn(page, 'admin')
  await page.goto('/attendance')
  const card = deviceCard(page)
  const address = card.getByLabel('Address on the office network')
  await expect(address).toHaveValue('', FIRST_LOAD)
  await expect(card.getByLabel('Port', { exact: true })).toHaveValue('4370')

  // A public address is refused on the field: the device is on the office network.
  await address.fill('8.8.8.8')
  await card.getByRole('button', { name: 'Save address' }).click()
  await expect(address).toHaveAttribute('aria-invalid', 'true')

  await address.fill('192.168.10.116')
  await card.getByRole('button', { name: 'Save address' }).click()
  await expect(page.getByText('Device address saved: the office PC picks it up within a minute')).toBeVisible()
  await expect(card.getByTestId('sync-log')).toContainText('Device address set to 192.168.10.116:4370')
  await expect(address).toHaveValue('192.168.10.116')
  await expect(address).toHaveAttribute('aria-invalid', 'false')
  // The agent last read the device at the address the first test reported, until it reads it at the new one.
  await expect(card.getByTestId('agent-address')).toHaveText('The agent is using 192.0.2.10:4370.')

  expect((await asAgent(page, token, 'check-in', { agent_version: '1.1.0' })).device).toEqual({ address: '192.168.10.116', port: 4370 })
  await asAgent(page, token, 'device', { status: 'ok', serial, address: '192.168.10.116:4370', users: [{ id: '41', name: 'E2E Agent' }] })
  await page.reload()
  await expect(deviceState(page)).toHaveText('Connected', FIRST_LOAD)
  await expect(address).toHaveValue('192.168.10.116')
  await expect(card.getByTestId('agent-address')).toHaveCount(0)

  // Cleared, and with none in its agent.ini either, the agent says it has no address: the card asks for one.
  await address.fill('')
  await card.getByRole('button', { name: 'Save address' }).click()
  await expect(page.getByText('Device address cleared', { exact: true })).toBeVisible()
  await expect(card.getByTestId('sync-log')).toContainText('Device address cleared (was 192.168.10.116:4370)')
  expect((await asAgent(page, token, 'check-in', { agent_version: '1.1.0' })).device).toEqual({ address: null, port: 4370 })
  await asAgent(page, token, 'device', { status: 'no_address', error: 'No device address: none from the server and none in agent.ini' })
  await page.reload()
  await expect(deviceState(page)).toHaveText('No device address: set it below', FIRST_LOAD)
  await expect(card.getByTestId('sync-log')).toContainText('No device address on the office PC')
  // Where it last read the device is history now, not where it looks.
  await expect(card.getByTestId('agent-address')).toHaveCount(0)
})

test('the agent is downloaded from the device card, by those who manage attendance only', async ({ page }) => {
  test.skip(token === '', 'needs the device from the first test')
  const admin = await staffApi(page, 'admin')
  const { meta } = await admin.get<{ meta: { agent_package: { version: string; filename: string } | null } }>('admin/attendance/devices')

  await signIn(page, 'admin')
  await page.goto('/attendance')
  const card = deviceCard(page)
  const download = card.getByRole('button', { name: 'Download the agent' })
  await expect(download).toBeVisible(FIRST_LOAD)
  const steps = card.getByTestId('agent-install-steps')
  await expect(steps).toContainText('First set the device’s address above.')
  await expect(steps).toContainText('If Windows says “Windows protected your PC”, press More info, then Run anyway')
  if (meta.agent_package === null) {
    // Nothing uploaded to this API (deploy/upload-agent-package.sh puts it on the server): the button says so.
    await expect(download).toBeDisabled()
    await expect(card.getByTestId('agent-download')).toContainText('Not on the server yet')
  } else {
    await expect(card.getByTestId('agent-download')).toContainText(`Version ${meta.agent_package.version}`)
    const saving = page.waitForEvent('download')
    await download.click()
    expect((await saving).suggestedFilename()).toBe(meta.agent_package.filename)
  }

  // The accountant sees everyone's attendance, but neither the download nor a way to change the address.
  await signIn(page, 'accountant')
  await page.goto('/attendance')
  await expect(deviceState(page)).toBeVisible(FIRST_LOAD)
  await expect(card.getByLabel('Address on the office network')).toBeDisabled()
  await expect(card.getByRole('button', { name: 'Save address' })).toHaveCount(0)
  await expect(card.getByRole('button', { name: 'Download the agent' })).toHaveCount(0)
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

test('someone who hasn’t signed in yet is matched and counts; switched off the device, they leave attendance and salary shows them as not tracked', async ({ page }) => {
  test.skip(token === '', 'needs the device from the first test')
  const suffix = String(Date.now()).slice(-5)
  const name = `Shirin Akter ${suffix}`
  const month = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()).slice(0, 7)
  // Invited this month, so on this month's sheets only; she never signs in.
  const admin = await staffApi(page, 'admin')
  const newcomer = (await admin.post<{ data: { id: number; employee_code: string } }>('admin/staff', { name, email: `shirin.${suffix}@e2e.test`, role: 'sales_agent', designation: 'Front desk', joined_on: `${month}-01`, locale: 'en' })).data
  const choice = `${name} · ${newcomer.employee_code} (hasn’t signed in yet)`
  // ID 42 punches without being in the device's user list (deleted from the device since, say).
  await asAgent(page, token, 'punches', { serial, punches: [{ user_id: '42', time: `${recentWorkingDay()} 10:50:00` }] })

  await signIn(page, 'admin')
  await page.goto('/attendance')
  const card = deviceCard(page)
  await card.getByRole('button', { name: /^Device users/ }).click(FIRST_LOAD)
  const users = page.getByRole('dialog', { name: /^Users on / })
  const id42 = users.getByTestId('device-users').locator('li').filter({ hasText: 'ID 42' })
  await expect(id42).toContainText('Not on the device any more')
  await expect(users.getByTestId('device-users').locator('li').filter({ hasText: 'ID 41' })).not.toContainText('Not on the device any more')
  await expect(id42.getByLabel('Staff member').locator('option', { hasText: name })).toHaveText(choice)
  await id42.getByLabel('Staff member').selectOption({ label: choice })
  await expect(page.getByText('Saved', { exact: true })).toBeVisible()
  await users.getByRole('button', { name: 'Done' }).click()

  // In this month's attendance until switched off on her record.
  await page.goto(`/attendance?month=${month}`)
  const table = page.getByTestId('attendance-month-table')
  await expect(table.locator('tbody tr').filter({ hasText: name })).toHaveCount(1, FIRST_LOAD)

  await page.goto(`/staff/${newcomer.id}`)
  const uses = page.getByRole('switch', { name: /Uses the fingerprint device/ })
  await expect(uses).toHaveAttribute('aria-checked', 'true', FIRST_LOAD)
  await expect(page.getByText('Off: left out of attendance, and paid as a fixed monthly salary (no attendance cuts). A change applies to every month not finalised yet.')).toBeVisible()
  await uses.click()
  await page.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByText('Saved', { exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('switch', { name: /Uses the fingerprint device/ })).toHaveAttribute('aria-checked', 'false', FIRST_LOAD)

  await page.goto(`/attendance?month=${month}`)
  await expect(table.locator('tbody tr').filter({ hasText: 'E2E sales_agent' })).toHaveCount(1, FIRST_LOAD)
  await expect(table.locator('tbody tr').filter({ hasText: name })).toHaveCount(0)

  // Still on the salary sheet, with no attendance counts and nothing to open day by day.
  await page.goto(`/payroll?month=${month}`)
  const row = page.getByTestId('payroll-table').locator('tbody tr').filter({ hasText: name })
  await expect(row).toContainText('Not tracked', FIRST_LOAD)
  await expect(row.getByRole('button', { name: `Days — ${name} (Doesn’t use the fingerprint device)` })).toBeDisabled()
})
