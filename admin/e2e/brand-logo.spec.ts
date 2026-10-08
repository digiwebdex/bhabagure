import { expect, test } from '@playwright/test'

import { API_URL, FIRST_LOAD, PHOTO, signIn } from './helpers'

/** docs/brand-logo.md: Site settings → Brand changes the logos everywhere, and "Use the original" brings them back. */

const publicLogos = async (request: import('@playwright/test').APIRequestContext) =>
  ((await (await request.get(`${API_URL}/api/v1/public/settings`)).json()) as { data: { logos: Record<string, string | null> } }).data.logos

test('a new light logo is uploaded in Site settings, shows in the sidebar at once, and the original comes back', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Brand: logo and icon', exact: true }) })
  const light = card.getByTestId('brand-logoLight')
  const sidebarLogo = page.getByRole('complementary', { name: 'Main navigation' }).getByRole('img').first()
  await expect(light).toContainText('Using the original.', FIRST_LOAD)
  await expect(sidebarLogo).toHaveAttribute('src', '/brand/logo-wordmark-light.png')

  try {
    await light.getByRole('button', { name: 'Choose image' }).click()
    const picker = page.getByRole('dialog', { name: 'Light logo' })
    await picker.locator('input[type=file]').setInputFiles(PHOTO)
    await expect(picker).toBeHidden({ timeout: 30_000 })
    await expect(light).toContainText('Using the image you chose.')
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()

    // The website gets it, and the admin's own sidebar follows without a reload.
    await expect.poll(async () => (await publicLogos(page.request)).logoLight ?? '').toMatch(/-card\.webp$/)
    await expect(sidebarLogo).toHaveAttribute('src', /-card\.webp$/)
    expect((await publicLogos(page.request)).logo).toBeNull()

    await light.getByRole('button', { name: 'Use the original' }).click()
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
    await expect(sidebarLogo).toHaveAttribute('src', '/brand/logo-wordmark-light.png')
    expect((await publicLogos(page.request)).logoLight).toBeNull()
  } finally {
    if ((await publicLogos(page.request)).logoLight) {
      await page.request.put(`${API_URL}/api/v1/admin/settings/brand`, { data: { value: { logo: null, logoLight: null, icon: null } } })
    }
  }
})
