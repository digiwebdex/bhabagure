import { expect, test } from '@playwright/test'
import { resolve } from 'node:path'

import { API_URL, FIRST_LOAD, signIn } from './helpers'

/** The website's own hero video: a real MP4, 5.4 MB, so it goes up in pieces. */
const VIDEO = resolve(import.meta.dirname, '../../web/public/media/hero.mp4')

/**
 * docs/hero-video.md: Site settings → Home page video. A video uploaded (in pieces) or linked is what the website's
 * public settings give the home page; "Use the original video" goes back to the one the site ships with.
 */
test('staff replace the home page video with an upload or a link, and go back to the original', async ({ page }) => {
  test.setTimeout(120_000)
  await signIn(page, 'admin')
  await page.goto('/settings')
  const current = page.getByTestId('hero-current')
  // The card's own request queues behind the page's others on the one-request-at-a-time e2e API: allow for it.
  await expect(current).toContainText('The original video the website came with', { timeout: 2 * FIRST_LOAD.timeout })
  const publicHero = async () => ((await (await page.request.get(`${API_URL}/api/v1/public/settings`)).json()) as { data: { hero: { videoUrl: string } | null } }).data.hero

  // Upload: checked and previewed here, the poster taken from it (or said to be missing), then sent in pieces.
  const upload = page.getByTestId('hero-upload')
  await upload.getByLabel('Video file').setInputFiles(VIDEO)
  await expect(upload).toContainText('hero.mp4 · 5.2 MB')
  await expect(upload.getByText(/Taking the poster/)).toHaveCount(0, { timeout: 20_000 })
  await upload.getByRole('button', { name: 'Upload and use this video' }).click()
  await expect(page.getByText('The home page video is changed.')).toBeVisible({ timeout: 60_000 })
  await expect(current).toContainText('hero.mp4 · 5.2 MB')
  expect((await publicHero())?.videoUrl).toMatch(/\/storage\/hero\/\w+\.mp4$/)

  // A page that shows a video is refused with the reason; a direct link to the file is taken.
  await page.getByRole('tab', { name: 'Use a link' }).click()
  const link = page.getByTestId('hero-link')
  await link.getByLabel('Direct link to an MP4 or WebM file').fill('https://www.youtube.com/watch?v=hero')
  await link.getByRole('button', { name: 'Use this link' }).click()
  await expect(link).toContainText('This is a link to a video page, not to the video file.')
  await link.getByLabel('Direct link to an MP4 or WebM file').fill('https://cdn.e2e.test/hero/winter.mp4')
  await link.getByRole('button', { name: 'Use this link' }).click()
  await expect(page.getByText('The home page video is changed.')).toBeVisible()
  await expect(current).toContainText('Linked: https://cdn.e2e.test/hero/winter.mp4')
  expect((await publicHero())?.videoUrl).toBe('https://cdn.e2e.test/hero/winter.mp4')

  // Back to the video the website ships with.
  await current.getByRole('button', { name: 'Use the original video' }).click()
  await page.getByRole('dialog', { name: 'Are you sure?' }).getByRole('button', { name: 'Yes, continue' }).click()
  await expect(page.getByText('The home page shows the original video again.')).toBeVisible()
  await expect(current).toContainText('The original video the website came with')
  expect(await publicHero()).toBeNull()
})
