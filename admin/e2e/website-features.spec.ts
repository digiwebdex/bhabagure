import { expect, test } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/website-features.md: Site settings → Website features switches a feature off for the public website only; the
 * feature's own admin screen keeps working.
 */

const publicFeatures = async (request: import('@playwright/test').APIRequestContext) =>
  ((await (await request.get(`${API_URL}/api/v1/public/settings`)).json()) as { data: { features: Record<string, boolean> } }).data.features

test('a website feature is switched off and on again in Site settings, and its admin screen keeps working', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/settings')
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Website features', exact: true }) })
  const blog = card.getByRole('switch', { name: 'Blog' })
  // Nothing saved yet: every feature is on.
  await expect(blog).toHaveAttribute('aria-checked', 'true', FIRST_LOAD)
  await expect(card.getByRole('switch')).toHaveCount(9)

  try {
    await blog.click()
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible(FIRST_LOAD)
    expect(await publicFeatures(page.request)).toMatchObject({ blog: false, team: true, reviews: true })

    // The Blog screen still works while the public website hides the blog.
    await page.goto('/posts')
    await expect(page.getByRole('heading', { name: 'Blog', level: 1 })).toBeVisible(FIRST_LOAD)
    await expect(page.getByRole('link', { name: '+ New post' })).toBeVisible()
    await expect(page.getByRole('button', { name: /^Destinations \d+ posts?$/ })).toBeVisible(FIRST_LOAD)

    await page.goto('/settings')
    await expect(blog).toHaveAttribute('aria-checked', 'false', FIRST_LOAD)
    await blog.click()
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible(FIRST_LOAD)
    expect((await publicFeatures(page.request)).blog).toBe(true)
  } finally {
    // Whatever happened above, later tests see the website whole.
    if (!(await publicFeatures(page.request)).blog) {
      await page.goto('/settings')
      await blog.click()
      await card.getByRole('button', { name: 'Save', exact: true }).click()
      await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
    }
  }
})
