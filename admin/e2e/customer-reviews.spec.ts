import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'

import { API_URL, FIRST_LOAD, PHOTO, signIn } from './helpers'

/**
 * docs/customer-reviews.md: a customer's review sent from the website waits in Admin → Reviews, counted on the sidebar;
 * staff keep a photo off the website and approve it, and it reaches the public list.
 */
test('a customer’s review waits for approval; staff hide a photo and approve it, and it goes live', async ({ page }) => {
  const phone = `0171${String(Date.now()).slice(-7)}`
  const sent = await page.request.post(`${API_URL}/api/v1/public/reviews`, {
    headers: { Accept: 'application/json' },
    multipart: {
      name: 'Farhana Akter', phone, package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights', rating: '4', locale: 'en',
      review: 'Loved Muktinath and the drive to Jomsom. The hotel in Pokhara could have been closer to the lake.',
      'photos[0]': { name: 'jomsom.jpg', mimeType: 'image/jpeg', buffer: readFileSync(PHOTO) },
      'photos[1]': { name: 'group.jpg', mimeType: 'image/jpeg', buffer: readFileSync(PHOTO) },
    },
  })
  expect(sent.status(), await sent.text()).toBe(202)
  const live = async () => ((await (await page.request.get(`${API_URL}/api/v1/public/reviews`)).json()) as { data: { reviewerName: string; photos: unknown[] }[] }).data
  expect((await live()).some((review) => review.reviewerName === 'Farhana Akter')).toBe(false)

  await signIn(page, 'admin')
  // The sidebar counts it.
  await expect(page.getByTestId('nav-badge-reviews')).toHaveText('1', FIRST_LOAD)
  await page.getByRole('navigation').getByRole('link', { name: /Reviews/ }).click()

  const waiting = page.getByTestId('pending-reviews').locator('li').filter({ hasText: 'Farhana Akter' }).first()
  await expect(waiting).toContainText('Loved Muktinath', FIRST_LOAD)
  await expect(waiting).toContainText('No booking with this number')
  await waiting.getByRole('checkbox').nth(1).uncheck()
  await expect(waiting.getByRole('checkbox').nth(1)).not.toBeChecked()
  await waiting.getByRole('button', { name: /Approve/ }).click()
  await expect(page.getByText('Review approved — it’s on the website')).toBeVisible()
  await expect(page.getByTestId('pending-reviews')).toHaveCount(0)

  // Live, with the one photo left shown; in the list staff edit.
  await expect.poll(async () => (await live()).find((review) => review.reviewerName === 'Farhana Akter')?.photos.length ?? -1).toBe(1)
  await expect(page.getByRole('listitem').filter({ hasText: 'Farhana Akter' }).first()).toBeVisible()
})
