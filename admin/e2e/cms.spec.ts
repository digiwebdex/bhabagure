import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { API_URL, FIRST_LOAD, password, PHOTO, signIn } from './helpers'

test.describe('access', () => {
  test('a tour operator sees bookings and the catalogue, not the website screens', async ({ page }) => {
    await signIn(page, 'tour_operator')
    // Everyone lands on the Dashboard (docs/phase-5-admin-core.md §4.1).
    await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
    const nav = page.getByRole('navigation')
    await expect(nav.getByRole('link', { name: /Packages/ })).toBeVisible()
    await expect(nav.getByRole('link', { name: /Pricing/ })).toBeVisible()
    await expect(nav.getByRole('link', { name: /Blog/ })).toHaveCount(0)

    await page.goto('/posts')
    await expect(page.getByText("You don't have access to this screen")).toBeVisible()
  })

  test('a sales agent sees bookings and no website screens', async ({ page }) => {
    await signIn(page, 'sales_agent')
    await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
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
    await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible()
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
    await page.getByLabel('Destination').selectOption({ label: 'Nepal' })
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

    // Not publishable without a photo: the API says what's missing. The saved package's screen is still loading its
    // departures, tags and media on the one-request-at-a-time e2e API, so this answer queues behind them (FIRST_LOAD).
    await page.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByRole('alert').filter({ hasText: 'Add at least one photo.' })).toBeVisible(FIRST_LOAD)
    expect((await request.get(`${API_URL}/api/v1/public/packages/pokhara-lake-escape`)).status()).toBe(404)

    await page.getByRole('button', { name: '+ Add photo' }).click()
    const dialog = page.getByRole('dialog', { name: 'Choose an image' })
    await dialog.locator('input[type=file]').setInputFiles(PHOTO)
    // The upload makes the image's WebP sizes inside the request.
    await expect(dialog).toBeHidden({ timeout: 30_000 })
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

    try {
      await page.getByRole('button', { name: 'Unpublish' }).click()
      await expect(page.getByText('Unpublished — hidden from the website.')).toBeVisible()
      expect((await request.get(`${API_URL}/api/v1/public/packages/${slug}`)).status()).toBe(404)

      await page.getByRole('button', { name: 'Publish' }).click()
      await expect(page.getByText('Published — live on the website.')).toBeVisible()
      expect((await request.get(`${API_URL}/api/v1/public/packages/${slug}`)).status()).toBe(200)
    } finally {
      // Every later spec books this package: a failure part-way must not leave it off the website.
      artisan('tinker', `--execute=App\\Models\\TourPackage::query()->where('slug', '${slug}')->update(['status' => 'published', 'published_at' => DB::raw('COALESCE(published_at, NOW())')]); echo 'ok';`)
    }
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
    await page.getByLabel('Category').selectOption({ label: 'Destinations' })

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

  test('a visa service is refused until its processing time and requirements are in, then appears publicly (Phase 8 §4.C)', async ({ page, request }) => {
    await signIn(page, 'admin')
    await page.goto('/visas')
    await expect(page.getByText('No visa services yet')).toBeVisible(FIRST_LOAD)
    await page.getByRole('button', { name: '+ Add visa service' }).first().click()
    let dialog = page.getByRole('dialog')
    await dialog.getByLabel('Country (Bangla)').fill('মালয়েশিয়া')
    await dialog.getByLabel('Country (English)').fill('Malaysia')
    await dialog.getByLabel('Visa type (Bangla)').fill('ই-ভিসা')
    await dialog.getByLabel('Visa type (English)').fill('eVisa')
    await dialog.getByLabel('Country code').fill('my')
    await expect(dialog.getByLabel('Country code')).toHaveValue('MY')
    await dialog.getByLabel('Price per person (BDT)').fill('4200')
    await expect(dialog.getByText('BDT 4,200 · Including our service charge.', { exact: false })).toBeVisible()
    await dialog.getByLabel('Requirements (English)').fill('Passport valid for 6 months\nOne photo, white background')
    await dialog.getByRole('button', { name: 'Save' }).click()
    const row = page.getByRole('listitem').filter({ hasText: 'Malaysia · eVisa' })
    await expect(row).toContainText('BDT 4,200 · no processing time · 2 requirements')

    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByText('Say how long processing takes.')).toBeVisible()
    await expect(page.getByText('List the requirements in both languages, one per line.')).toBeVisible()
    expect((await (await request.get(`${API_URL}/api/v1/public/visas`)).json()).data).toHaveLength(0)

    await row.getByText('BDT 4,200 · no processing time').click()
    dialog = page.getByRole('dialog')
    await dialog.getByLabel('Processing time (English)').fill('3–5 working days')
    await dialog.getByLabel('Requirements (Bangla)').fill('৬ মাস মেয়াদি পাসপোর্ট\nসাদা ব্যাকগ্রাউন্ডে একটি ছবি')
    await dialog.getByRole('button', { name: 'Save' }).click()
    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(row.getByText('Published')).toBeVisible()

    const [visa] = (await (await request.get(`${API_URL}/api/v1/public/visas`)).json()).data
    expect([visa.slug, visa.countryCode, visa.price, visa.processing.bn, visa.requirements.bn]).toEqual(['malaysia-evisa', 'MY', 4200, '3–5 working days', ['৬ মাস মেয়াদি পাসপোর্ট', 'সাদা ব্যাকগ্রাউন্ডে একটি ছবি']])
  })

  test('the travel host needs a link before saving, then shows on the website with the video picked for it (docs/travel-host.md)', async ({ page, request }) => {
    await signIn(page, 'admin')
    await page.getByRole('navigation').getByRole('link', { name: 'H Travel host' }).click()
    await expect(page.getByRole('heading', { name: 'Travel host', level: 1 })).toBeVisible(FIRST_LOAD)
    await expect(page.getByText('No videos yet')).toBeVisible(FIRST_LOAD)
    await expect(page.getByLabel('Name (Bangla)')).toBeVisible(FIRST_LOAD)

    await page.getByLabel('Name (Bangla)').fill('শিশির দেব')
    await page.getByLabel('Name (English)').fill('Shishir Deb')
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(page.getByText('Add the Facebook page or the YouTube channel link: a card with neither links nowhere.').first()).toBeVisible()
    expect((await (await request.get(`${API_URL}/api/v1/public/creator`)).json()).data.profile).toBeNull()

    await page.getByLabel('YouTube channel link').fill('https://youtube.com/@shishirdeb?si=IWQwGLPek8MzQRRi')
    await page.getByLabel('Subscribers').fill('712000')
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
    // Stored without the tracking the shared link carried.
    await expect(page.getByLabel('YouTube channel link')).toHaveValue('https://youtube.com/@shishirdeb')

    await page.getByRole('button', { name: '+ Add video' }).first().click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('YouTube video link').fill('https://youtu.be/lI5NMGqg6xk?si=share')
    await expect(dialog.getByTestId('video-preview')).toHaveAttribute('src', 'https://i.ytimg.com/vi/lI5NMGqg6xk/hqdefault.jpg')
    await dialog.getByLabel('Title (English)').fill('Three countries for 1.2 lakh taka')
    await dialog.getByRole('button', { name: 'Save' }).click()
    const row = page.getByRole('listitem').filter({ hasText: 'Three countries for 1.2 lakh taka' })
    await expect(row).toContainText('youtu.be/lI5NMGqg6xk')

    expect((await (await request.get(`${API_URL}/api/v1/public/creator`)).json()).data.videos).toHaveLength(0)
    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(row.getByText('Published')).toBeVisible()
    const { profile, videos } = (await (await request.get(`${API_URL}/api/v1/public/creator`)).json()).data
    expect([profile.name.en, profile.youtube.url, profile.youtube.subscribers, profile.facebook]).toEqual(['Shishir Deb', 'https://youtube.com/@shishirdeb', 712000, null])
    expect(videos.map((video: { youtubeId: string }) => video.youtubeId)).toEqual(['lI5NMGqg6xk'])
  })

  test('an airline partner needs its logo before it can be published (docs/partners-and-payments.md)', async ({ page, request }) => {
    await signIn(page, 'admin')
    await page.getByRole('navigation').getByRole('link', { name: '✈ Airline partners' }).click()
    await expect(page.getByRole('heading', { name: 'Airline partners', level: 1 })).toBeVisible(FIRST_LOAD)
    await expect(page.getByText('No airline partners yet')).toBeVisible(FIRST_LOAD)

    await page.getByRole('button', { name: '+ Add airline' }).first().click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Airline (Bangla)').fill('স্কুট')
    await dialog.getByLabel('Airline (English)').fill('Scoot')
    await dialog.getByLabel('Airline website (optional)').fill('https://www.flyscoot.com')
    await dialog.getByRole('button', { name: 'Save' }).click()
    const row = page.getByRole('listitem').filter({ hasText: 'Scoot' })
    await expect(row).toContainText('flyscoot.com')

    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByText('Add the airline’s logo.')).toBeVisible()
    expect((await (await request.get(`${API_URL}/api/v1/public/partners`)).json()).data).toHaveLength(0)

    await row.getByRole('button', { name: /^Scoot/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Choose', exact: true }).click()
    const picker = page.getByRole('dialog', { name: 'Choose an image' })
    await picker.locator('input[type=file]').setInputFiles(PHOTO)
    await expect(picker).toBeHidden({ timeout: 30_000 })
    await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()

    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(row.getByText('Published')).toBeVisible()
    const [partner] = (await (await request.get(`${API_URL}/api/v1/public/partners`)).json()).data
    expect([partner.name.en, partner.websiteUrl, partner.logo.url]).toEqual(['Scoot', 'https://www.flyscoot.com', expect.stringMatching(/\/storage\/media\/.+\.webp$/)])
  })

  test('an offer banner needs its picture before it can be published (docs/offer-banners.md)', async ({ page, request }) => {
    await signIn(page, 'admin')
    await page.getByRole('navigation').getByRole('link', { name: '★ Offer banners' }).click()
    await expect(page.getByRole('heading', { name: 'Offer banners', level: 1 })).toBeVisible(FIRST_LOAD)
    await expect(page.getByText('No offer banners yet')).toBeVisible(FIRST_LOAD)

    await page.getByRole('button', { name: '+ Add banner' }).first().click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('What it says (Bangla)').fill('ঈদ অফার')
    await dialog.getByLabel('What it says (English)').fill('Eid offer')
    await dialog.getByLabel('Where it leads (optional)').fill('not a link')
    await dialog.getByRole('button', { name: 'Save' }).click()
    await expect(dialog.getByText('Give a full web address', { exact: false })).toBeVisible()

    await dialog.getByLabel('Where it leads (optional)').fill('/packages/nepal-mustang-adventure-tour-8-days-7-nights')
    await dialog.getByRole('button', { name: 'Save' }).click()
    const row = page.getByRole('listitem').filter({ hasText: 'Eid offer' })
    await expect(row).toContainText('/packages/nepal-mustang')

    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(page.getByText('Add the banner picture.')).toBeVisible()
    expect((await (await request.get(`${API_URL}/api/v1/public/offers`)).json()).data).toHaveLength(0)

    await row.getByRole('button', { name: /^Eid offer/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Choose', exact: true }).click()
    const picker = page.getByRole('dialog', { name: 'Choose an image' })
    await picker.locator('input[type=file]').setInputFiles(PHOTO)
    await expect(picker).toBeHidden({ timeout: 30_000 })
    await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()

    await row.getByRole('button', { name: 'Publish' }).click()
    await expect(row.getByText('Published')).toBeVisible()
    const [banner] = (await (await request.get(`${API_URL}/api/v1/public/offers`)).json()).data
    expect([banner.title.bn, banner.linkUrl]).toEqual(['ঈদ অফার', '/packages/nepal-mustang-adventure-tour-8-days-7-nights'])
    expect(banner.image.url).toMatch(/\/storage\/media\/.+\.webp$/)
  })

  test('media library rejects an oversized upload before sending it', async ({ page }) => {
    await signIn(page, 'admin')
    await page.goto('/media')
    await page.locator('input[type=file]').setInputFiles({ name: 'huge.jpg', mimeType: 'image/jpeg', buffer: Buffer.alloc(5 * 1024 * 1024 + 1) })
    await expect(page.getByText('Larger than 5 MB.')).toBeVisible()
  })
})
