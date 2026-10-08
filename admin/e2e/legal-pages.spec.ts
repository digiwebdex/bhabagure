import { expect, test } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn } from './helpers'

/**
 * docs/legal-pages.md: Website → Legal pages. The editor starts from the website's built-in draft; each publish is a new
 * version on the website at once, and earlier versions stay readable.
 */

test('the refund policy is rewritten from the draft and published twice; the website gets the latest and both versions stay', async ({ page }) => {
  await signIn(page, 'admin')
  await page.getByRole('link', { name: /Legal pages$/ }).click()
  const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Refund and cancellation policy' }) })
  await expect(card).toContainText(/Not published yet|Version \d+ on the website/, FIRST_LOAD)
  await card.getByRole('link', { name: 'Edit' }).click()

  // Before the first publish, the editor holds the built-in draft.
  await expect(page.getByLabel('Title (Bangla)')).not.toHaveValue('', FIRST_LOAD)
  const before = ((await (await page.request.get(`${API_URL}/api/v1/public/legal`)).json()) as { data: Record<string, { version: number } | null> }).data['refund-policy']?.version ?? 0

  const publish = async (text: string, note: string) => {
    const body = page.locator('.ProseMirror').first()
    await body.click()
    await page.keyboard.press('Control+A')
    await page.keyboard.type(text)
    await page.getByLabel('What changed (optional)').fill(note)
    await page.getByRole('button', { name: 'Publish' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Yes, continue' }).click()
    await expect(page.getByText(/^Version \d+ published$/)).toBeVisible()
  }
  await publish('৩০ দিন আগে বাতিল করলে পুরো টাকা ফেরত।', 'Our own policy')
  await publish('২১ দিন আগে বাতিল করলে পুরো টাকা ফেরত।', '21 days, not 30')

  const versions = page.getByTestId('legal-versions')
  await expect(versions.locator('li')).toHaveCount(before + 2)
  await expect(versions.locator('li').first()).toContainText(`Version ${before + 2}`)
  await expect(versions.locator('li').first()).toContainText('on the website')
  await expect(versions).toContainText('Our own policy')

  // The website's text is the latest; the earlier version still reads as it was.
  const live = ((await (await page.request.get(`${API_URL}/api/v1/public/legal`)).json()) as { data: Record<string, { version: number; body: { bn: string } }> }).data['refund-policy']
  expect(live.version).toBe(before + 2)
  expect(live.body.bn).toContain('২১ দিন আগে')
  await versions.locator('li').nth(1).getByRole('button', { name: 'Read' }).click()
  await expect(page.getByRole('dialog')).toContainText('৩০ দিন আগে')
})
