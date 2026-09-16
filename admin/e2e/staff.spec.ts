import { expect, test } from '@playwright/test'

import { FIRST_LOAD, PHOTO, signIn } from './helpers'

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §4: staff join by invitation and set their own password; a staff document is
 * uploaded, opened and archived while the Vault badge follows; the super admin makes a custom role in the matrix.
 */

test('an admin adds a staff member, who sets their own password from the one-time invitation link', async ({ page, browser }) => {
  const suffix = String(Date.now()).slice(-5)
  const name = `Tania Sultana ${suffix}`
  await signIn(page, 'admin')
  await page.goto('/staff')
  await page.getByRole('button', { name: '+ Add staff' }).click({ timeout: FIRST_LOAD.timeout })
  const dialog = page.getByRole('dialog', { name: 'Add a staff member' })
  await dialog.getByLabel('Name', { exact: true }).fill(name)
  await dialog.getByLabel('Sign-in email').fill(`tania.${suffix}@e2e.test`)
  await dialog.getByLabel('Mobile').fill('01711-223355')
  await dialog.getByLabel('Role').selectOption('sales_agent')
  await dialog.getByLabel('Designation').fill('Content & social')
  await dialog.getByRole('button', { name: 'Add and invite' }).click()

  // The link is shown once. The e2e mailer only writes a log, and the admin is told the email reached nobody.
  const invitation = page.getByRole('dialog', { name: `Invitation for ${name}` })
  await expect(invitation).toContainText('Email isn’t switched on for this server yet')
  const url = await invitation.getByTestId('invitation-link').inputValue()
  expect(url).toMatch(/\/accept-invite#token=[0-9a-f]{64}$/)
  await invitation.getByRole('button', { name: 'Done' }).click()
  await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible()
  await expect(page.getByRole('main')).toContainText('Invited')

  // The newcomer, in a browser of their own.
  const context = await browser.newContext()
  const newcomer = await context.newPage()
  await newcomer.goto(url)
  await expect(newcomer.getByRole('heading', { name: 'Set your password' })).toBeVisible(FIRST_LOAD)
  await expect(newcomer).not.toHaveURL(/#token=/)
  await newcomer.getByLabel('New password (10+ characters)').fill('a-long-e2e-password')
  await newcomer.getByLabel('Repeat the new password').fill('a-long-e2e-password')
  await newcomer.getByRole('button', { name: 'Set password and sign in' }).click()
  await expect(newcomer.getByText(`Welcome, ${name}`)).toBeVisible(FIRST_LOAD)
  // A sales agent: no Staff screen in their sidebar.
  await expect(newcomer.getByRole('navigation').getByRole('link', { name: 'Bookings' })).toBeVisible()
  await expect(newcomer.getByRole('navigation').getByRole('link', { name: 'Staff', exact: true })).toHaveCount(0)

  // The link works once.
  const again = await context.newPage()
  await again.goto(url)
  await expect(again.getByRole('heading', { name: 'This link doesn’t work' })).toBeVisible(FIRST_LOAD)
  await context.close()

  await page.goto('/staff')
  await expect(page.getByTestId('staff-table').locator('tbody tr').filter({ hasText: name })).toContainText('Active', FIRST_LOAD)
})

test('a staff document is uploaded, opened and archived, and the Vault badge follows', async ({ page }) => {
  await signIn(page, 'admin')
  const counts = page.waitForResponse((response) => response.url().includes('/api/v1/admin/nav-counts'))
  await page.goto('/staff?search=E2E%20sales_agent')
  const before = Number(((await (await counts).json()) as { data: Record<string, { count: number }> }).data.staff_documents?.count ?? 0)
  const badge = page.getByTestId('nav-badge-staff_documents')

  await page.getByTestId('staff-table').locator('tbody tr').filter({ hasText: 'E2E sales_agent' }).getByRole('link', { name: 'Open record — E2E sales_agent' }).click(FIRST_LOAD)
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Documents' }) })
  await card.getByRole('button', { name: '+ Upload document' }).click(FIRST_LOAD)
  const dialog = page.getByRole('dialog', { name: 'Upload a staff document' })
  await dialog.getByLabel('Document', { exact: true }).selectOption('passport')
  await dialog.getByLabel('Number').fill('BW0912345')
  await dialog.getByLabel('Expires').fill(new Date(Date.now() + 10 * 86_400_000).toISOString().slice(0, 10))
  await dialog.getByLabel('File').setInputFiles(PHOTO)
  await dialog.getByRole('button', { name: 'Upload' }).click()
  await expect(page.getByText('Document uploaded')).toBeVisible()

  const item = card.getByTestId('staff-documents').locator('li').filter({ hasText: 'BW0912345' })
  await expect(item).toContainText('Expiring')
  await expect(item).toContainText(/\d+ days left/)
  await expect(badge).toHaveText(String(before + 1))

  // Decrypted in memory and opened in a new tab; the API records the opening.
  const popup = page.waitForEvent('popup')
  await item.getByRole('button', { name: 'Open Passport' }).click()
  await expect.poll(async () => (await popup).url()).toMatch(/^blob:/)
  await (await popup).close()

  await badge.click()
  await expect(page).toHaveURL(/\/vault\?status=attention$/)
  const row = page.getByTestId('staff-documents-table').locator('tbody tr').filter({ hasText: 'BW0912345' })
  await row.getByRole('button', { name: 'Archive — E2E sales_agent · Passport' }).click(FIRST_LOAD)
  const archive = page.getByRole('dialog', { name: 'Archive Passport?' })
  await archive.getByLabel('Why').fill('Renewed; the new passport comes next week')
  await archive.getByRole('button', { name: 'Archive' }).click()
  await expect(page.getByText('Archived', { exact: true })).toBeVisible()
  if (before === 0) await expect(badge).toHaveCount(0)
  else await expect(badge).toHaveText(String(before))
})

test('the super admin makes a custom role and grants it a permission from the matrix', async ({ page }) => {
  const role = `Front desk ${String(Date.now()).slice(-5)}`
  await signIn(page, 'super_admin')
  await page.goto('/roles')
  await page.getByRole('button', { name: '+ New role' }).click(FIRST_LOAD)
  const dialog = page.getByRole('dialog', { name: 'New role' })
  await dialog.getByLabel('Name in English').fill(role)
  await dialog.getByLabel('Name in Bangla').fill('ফ্রন্ট ডেস্ক')
  await dialog.getByLabel('View customers').check()
  await dialog.getByRole('button', { name: 'Create role' }).click()
  await expect(page.getByText('Role created')).toBeVisible()
  await expect(page.getByTestId('roles-table').locator('tbody tr').filter({ hasText: role })).toContainText('Custom role')

  const matrix = page.getByTestId('permission-matrix')
  await expect(matrix.getByRole('switch', { name: 'View customers' })).toHaveAttribute('aria-checked', 'true')
  await matrix.getByRole('switch', { name: 'Manage customers' }).click()
  await expect(page.getByText(`${role} can now: Manage customers`)).toBeVisible()
  await expect(matrix.getByRole('switch', { name: 'Manage customers' })).toHaveAttribute('aria-checked', 'true')
  // Role management itself is never switchable.
  await expect(matrix.getByRole('switch', { name: 'Manage roles and permissions' })).toHaveCount(0)
})
