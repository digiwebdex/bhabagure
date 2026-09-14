import { expect, test } from '@playwright/test'

import { API_URL, password, PHOTO, signIn } from './helpers'

test.describe('access', () => {
  test('a tour operator sees bookings and the catalogue, not the website screens', async ({ page }) => {
    await signIn(page, 'tour_operator')
    await expect(page).toHaveURL(/\/bookings$/)
    const nav = page.getByRole('navigation')
    await expect(nav.getByRole('link', { name: /Packages/ })).toBeVisible()
    await expect(nav.getByRole('link', { name: /Pricing/ })).toBeVisible()
    await expect(nav.getByRole('link', { name: /Blog/ })).toHaveCount(0)

    await page.goto('/posts')
    await expect(page.getByText("You don't have access to this screen")).toBeVisible()
  })

  test('a sales agent sees bookings and no website screens', async ({ page }) => {
    await signIn(page, 'sales_agent')
    await expect(page).toHaveURL(/\/bookings$/)
    const nav = page.getByRole('navigation')
    await expect(nav.getByRole('link', { name: /Bookings/ })).toBeVisible()
    await expect(nav.getByRole('link', { name: /Packages|Blog|Pricing/ })).toHaveCount(0)
  })

  test('a new hire must replace the temporary password first', async ({ page }) => {
    await signIn(page, 'new.hire')
    await expect(page).toHaveURL(/\/change-password$/)
    await page.goto('/packages')
    await expect(page).toHaveURL(/\/change-password$/)

    await page.getByLabel('Current password').fill(password())
    await page.getByLabel(/^New password/).fill('a-brand-new-password')
    await page.getByLabel('Repeat the new password').fill('a-brand-new-password')
    await page.getByRole('button', { name: 'Change password' }).click()
    await expect(page).toHaveURL(/\/bookings$/)
  })

  test('the session survives a reload straight after navigating (refresh cookie, grace window)', async ({ page }) => {
    await signIn(page, 'admin')
    // The reload reuses the refresh cookie while the previous load's refresh is still settling. This signed staff out
    // before the reuse grace window (fails with AUTH_REFRESH_REUSE_GRACE_SECONDS=0).
    await page.goto('/packages')
    await page.reload()
    await expect(page.getByRole('heading', { name: 'Packages', level: 1 })).toBeVisible()
  })
})

test.describe('packages', () => {
  test('create a draft, see the checklist, add a photo, publish, and find it on the public API', async ({ page, request }) => {
    await signIn(page, 'tour_operator')
    await page.goto('/packages')
    await page.getByRole('link', { name: '+ New package' }).click()

    await page.getByLabel('Title (Bangla)', { exact: true }).fill('পোখারা লেক এস্কেপ')
    await page.getByLabel('Title (English)', { exact: true }).fill('Pokhara Lake Escape')
    await expect(page.getByLabel('Web address (slug)')).toHaveValue('pokhara-lake-escape')
    await page.getByLabel('Package code').fill('Nepal 77')
    await page.getByLabel('Destination').selectOption({ label: 'নেপাল · Nepal' })
    await page.getByLabel('Days').fill('৪') // Bengali digits are accepted
    await page.getByLabel('Nights').fill('3')
    await page.getByLabel('Regular price per person').fill('32000')
    await expect(page.getByText('BDT 32,000 per person')).toBeVisible()

    await page.getByRole('button', { name: '+ Add day' }).click()
    await page.getByLabel('Title (English)', { exact: true }).nth(1).fill('DHAKA ➔ POKHARA')
    await page.getByLabel('What happens (English)').fill('Fly to Kathmandu, bus to Pokhara.')
    await page.getByRole('button', { name: '+ Add included item' }).click()
    await page.getByLabel('English', { exact: true }).first().fill('Hotel with breakfast')

    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page).toHaveURL(/\/packages\/\d+$/)
    await expect(page.getByText('Draft').first()).toBeVisible()

    // Not publishable without a photo: the API says what's missing.
    await page.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByRole('alert').filter({ hasText: 'Add at least one photo.' })).toBeVisible()
    expect((await request.get(`${API_URL}/api/v1/public/packages/pokhara-lake-escape`)).status()).toBe(404)

    await page.getByRole('button', { name: '+ Add photo' }).click()
    const dialog = page.getByRole('dialog', { name: 'Choose an image' })
    await dialog.locator('input[type=file]').setInputFiles(PHOTO)
    await expect(dialog).toBeHidden()
    await expect(page.getByText('Cover', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByText('Published — live on the website.')).toBeVisible()

    const live = await request.get(`${API_URL}/api/v1/public/packages/pokhara-lake-escape`)
    expect(live.status()).toBe(200)
    const body = await live.json()
    expect(body.data.title).toEqual({ bn: 'পোখারা লেক এস্কেপ', en: 'Pokhara Lake Escape' })
    expect(body.data.durationDays).toBe(4)
    expect(body.data.images[0].url).toMatch(/\/storage\/media\/.+-detail\.webp$/)
    expect(body.data.images[0].variants.card.width).toBe(800)
  })

  test('a published package can be taken off the website and put back', async ({ page, request }) => {
    const slug = 'nepal-mustang-adventure-tour-8-days-7-nights'
    await signIn(page, 'admin')
    await page.goto('/packages')
    await page.getByRole('link', { name: /NEPAL MUSTANG/ }).click()

    await page.getByRole('button', { name: 'Unpublish' }).click()
    await expect(page.getByText('Unpublished — hidden from the website.')).toBeVisible()
    expect((await request.get(`${API_URL}/api/v1/public/packages/${slug}`)).status()).toBe(404)

    await page.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByText('Published — live on the website.')).toBeVisible()
    expect((await request.get(`${API_URL}/api/v1/public/packages/${slug}`)).status()).toBe(200)
  })

  test('leaving with unsaved changes asks first', async ({ page }) => {
    await signIn(page, 'admin')
    await page.goto('/packages')
    await page.getByRole('link', { name: /NEPAL MUSTANG/ }).click()
    await page.getByLabel('Card summary (English)').fill('Changed but not saved')
    await page.getByRole('link', { name: 'Back' }).click()
    await expect(page.getByText('You have unsaved changes.')).toBeVisible()
    await page.getByRole('button', { name: 'Keep editing' }).click()
    await expect(page.getByLabel('Card summary (English)')).toHaveValue('Changed but not saved')
  })
})

test.describe('website content', () => {
  test('a blog post is sanitised, scheduled for later and hidden until then', async ({ page, request }) => {
    await signIn(page, 'admin')
    await page.goto('/posts/new')
    await page.getByLabel('Title (Bangla)', { exact: true }).fill('শীতে সাজেক')
    await page.getByLabel('Title (English)', { exact: true }).fill('Sajek in winter')
    await page.getByLabel('Excerpt (Bangla)').fill('মেঘের দেশে তিন দিন।')
    await page.getByLabel('Excerpt (English)').fill('Three days above the clouds.')
    await page.getByLabel('Category').selectOption({ label: 'গন্তব্য · Destinations' })

    await page.locator('.ProseMirror').nth(0).click()
    await page.keyboard.type('মেঘ আর পাহাড়।')
    await page.locator('.ProseMirror').nth(1).click()
    await page.getByRole('button', { name: 'Bold' }).nth(1).click()
    await page.keyboard.type('Clouds and hills.')

    await page.getByRole('button', { name: 'Save', exact: true }).first().click()
    await expect(page).toHaveURL(/\/posts\/\d+$/)

    await page.getByLabel('Publish later (optional)').fill('2030-01-01T09:00')
    await page.getByRole('button', { name: 'Schedule' }).click()
    await expect(page.getByText('Scheduled — it goes live at the chosen time.')).toBeVisible()

    const slug = await page.getByLabel('Web address (slug)').inputValue()
    expect(slug).toBe('sajek-in-winter')
    expect((await request.get(`${API_URL}/api/v1/public/posts/${slug}`)).status()).toBe(404)
  })

  test('a review starts as a draft and appears publicly once published', async ({ page, request }) => {
    await signIn(page, 'admin')
    await page.goto('/reviews')
    await page.getByRole('button', { name: '+ Add review' }).first().click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Review (Bangla)').fill('খুব ভালো ব্যবস্থাপনা।')
    await dialog.getByLabel('Customer name').fill('Nusrat Jahan')
    await dialog.getByRole('button', { name: 'Save' }).click()
    await expect(page.getByText('Nusrat Jahan')).toBeVisible()

    expect((await (await request.get(`${API_URL}/api/v1/public/reviews`)).json()).data).toHaveLength(0)
    await page.getByRole('listitem').filter({ hasText: 'Nusrat Jahan' }).getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByRole('listitem').filter({ hasText: 'Nusrat Jahan' }).getByText('Published')).toBeVisible()
    expect((await (await request.get(`${API_URL}/api/v1/public/reviews`)).json()).data[0].reviewerName).toBe('Nusrat Jahan')
  })

  test('pricing: the supplement is separate from the slab, and the worked example follows the website maths', async ({ page, request }) => {
    await signIn(page, 'tour_operator')
    await page.getByRole('link', { name: /Pricing/ }).click()

    const example = page.getByRole('table')
    // 1 traveller in a shared room: list price, no uplift. 75,000 + 2% service = 76,500.
    await expect(example.getByRole('row').nth(1)).toContainText('BDT 75,000')
    await expect(example.getByRole('row').nth(1)).toContainText('BDT 76,500')

    await page.getByLabel('Supplement % of the per-person rate').fill('15')
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(page.getByText('Saved').first()).toBeVisible()

    const pricing = (await (await request.get(`${API_URL}/api/v1/public/pricing`)).json()).data
    expect(pricing.singleRoomSupplementPercent).toBe(15)
    expect(pricing.slabs[0]).toEqual({ minPax: 1, discountPercent: 0 })
  })

  test('media library rejects an oversized upload before sending it', async ({ page }) => {
    await signIn(page, 'admin')
    await page.goto('/media')
    await page.locator('input[type=file]').setInputFiles({ name: 'huge.jpg', mimeType: 'image/jpeg', buffer: Buffer.alloc(5 * 1024 * 1024 + 1) })
    await expect(page.getByText('Larger than 5 MB.')).toBeVisible()
  })
})
