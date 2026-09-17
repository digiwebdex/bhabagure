import { expect, test, type Page } from '@playwright/test';

const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

const headerHeight = (page: Page) => page.locator('header').first().evaluate((el) => Math.round(el.getBoundingClientRect().height));

test.describe('header', () => {
  test('one 73px row from 900px up, two rows (120px) below', async ({ page }) => {
    // Both languages: the Bangla labels are the longer ones, and the team page link made seven.
    for (const path of ['/en', '/']) {
      for (const [width, expected] of [[1280, 73], [900, 73], [899, 120], [390, 120]] as const) {
        await page.setViewportSize({ width, height: 800 });
        await page.goto(path);
        expect(await headerHeight(page), `header at ${width}px on ${path}`).toBe(expected);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `no sideways scroll at ${width}px on ${path}`).toBe(true);
      }
    }
  });

  test('the Book item in the ☰ sheet opens the booking modal instead of navigating', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto('/en');
    await page.getByRole('button', { name: 'Menu' }).click();
    await page.locator('#site-menu').getByRole('button', { name: 'Book now' }).click();
    await expect(page.getByRole('dialog', { name: 'Book online' })).toBeVisible();
    await expect(page).toHaveURL(/\/en$/);
  });
});

test.describe('search and package grid', () => {
  const counterCount = async (page: Page) => {
    const text = await page.locator('#search-results-summary').first().innerText();
    const match = /^(\d+) packages? match/.exec(text);
    return match ? Number(match[1]) : 0;
  };
  const cardCount = (page: Page) => page.locator('#packages article').count();

  test('the result counter always describes exactly the cards shown', async ({ page }) => {
    await page.goto('/en');
    expect(await counterCount(page)).toBe(await cardCount(page));

    await page.locator('#packages').getByRole('button', { name: 'Thailand' }).click();
    expect(await counterCount(page)).toBe(await cardCount(page));
    await expect(page.locator('#search select').first()).toHaveValue('thailand');

    await page.locator('#search select').first().selectOption('any');
    await page.locator('#search select').nth(1).selectOption('low');
    expect(await counterCount(page)).toBe(await cardCount(page));
    expect(await cardCount(page)).toBeGreaterThan(0);
  });

  test('rapid stepper clicks are all counted and drive per-person slab pricing on the cards', async ({ page }) => {
    await page.goto('/en');
    const mustangPrice = page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).locator('.text-price');
    await expect(mustangPrice).toHaveText('৳ 75,000');

    // Eight clicks dispatched in one tick: a stepper reading a captured render value would drop most of them.
    await page.getByRole('button', { name: 'One more traveller' }).evaluate((button: HTMLButtonElement) => {
      for (let i = 0; i < 8; i += 1) button.click();
    });
    await expect(page.locator('#search output')).toHaveText('10');
    await expect(mustangPrice).toHaveText('৳ 66,000'); // 10+ travellers: −12%
    await expect(page.locator('#search-results-summary').first()).toContainText('per-person price for 10 travellers');
  });

  test('package photos load through the image optimizer', async ({ page }) => {
    await page.goto('/en');
    const img = page.locator('#packages article img').first();
    await img.scrollIntoViewIfNeeded();
    await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
  });
});

test.describe('package detail', () => {
  test('a card opens the modal at /packages/<slug>; back closes it', async ({ page }) => {
    await page.goto('/en');
    await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('link', { name: /NEPAL MUSTANG/ }).click();
    const dialog = page.getByRole('dialog', { name: /NEPAL MUSTANG/ });
    await expect(dialog).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/en/packages/${MUSTANG}$`));

    await page.goBack();
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(/\/en$/);
  });

  test('the modal slab chips and stepper agree with the pricing rules', async ({ page }) => {
    await page.goto('/en');
    await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('link', { name: /NEPAL MUSTANG/ }).click();
    const dialog = page.getByRole('dialog', { name: /NEPAL MUSTANG/ });
    await dialog.getByRole('button', { name: /^4 people/ }).click();
    await expect(dialog.getByText('Group total').locator('..')).toContainText('৳ 2,82,000'); // 70,500 × 4
    await dialog.getByRole('button', { name: /^1 person/ }).click();
    await expect(dialog).toContainText('a single room adds 12% at booking');
  });

  test('a direct visit renders the full package page', async ({ page }) => {
    await page.goto(`/packages/${MUSTANG}`);
    await expect(page.getByRole('heading', { level: 1, name: /NEPAL MUSTANG/ })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
  });
});

test.describe('language', () => {
  test('Bangla uses Bengali digits, English uses Latin digits with en-IN grouping', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#packages')).toContainText('৳ ৭৫,০০০');
    await page.goto('/en');
    await expect(page.locator('#packages')).toContainText('৳ 75,000');
    await expect(page.locator('#packages')).not.toContainText('৭৫');
  });

  test('/bn redirects to the unprefixed Bangla URL', async ({ page }) => {
    const response = await page.request.get('/bn/blog', { maxRedirects: 0 });
    expect(response.status()).toBe(308);
    expect(response.headers().location).toBe('/blog');
  });
});

test.describe('site basics', () => {
  test('the footer names the payment methods on one line, through the image optimizer', async ({ page }) => {
    await page.goto('/en');
    const strip = page.locator('footer img').filter({ hasNot: page.locator('[alt="Bhabaghure Holidays"]') }).last();
    await expect(strip).toHaveAttribute('alt', /Visa, Mastercard/);
    await expect(strip).toHaveAttribute('src', /_next\/image\?url=%2Fpayments%2Fpay-with-sslcommerz\.png/);

    await page.goto('/');
    await expect(page.locator('footer img').last()).toHaveAttribute('alt', /বিকাশ, নগদ/);
  });

  test('an unknown address answers 404 with the site’s own page, in the visitor’s language', async ({ page }) => {
    const response = await page.goto('/en/no-such-page');
    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Bhabaghure Holidays' })).toBeVisible();
    await page.getByRole('link', { name: '← Back to the home page' }).click();
    await expect(page).toHaveURL(/\/en$/);

    expect((await page.goto('/no-such-page'))?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'পাতাটি পাওয়া যায়নি' })).toBeVisible();
  });

  test('pages carry the icon and refuse framing and MIME sniffing', async ({ page }) => {
    const response = await page.goto('/en');
    const headers = response!.headers();
    expect(headers['x-frame-options']).toBe('DENY');
    expect(headers['content-security-policy']).toContain("frame-ancestors 'none'");
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
    const icon = await page.locator('link[rel="icon"]').first().getAttribute('href');
    expect(icon).toBeTruthy();
    expect((await page.request.get(icon!)).status()).toBe(200);
  });

  test('no menu link points at a section the CMS left empty', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/en');
    // The desktop row and the footer link to departures; the gallery is linked from the ☰ sheet only.
    const departures = await page.locator('#departures').count();
    await expect(page.locator('header a[href$="#departures"]'), 'header link to #departures').toHaveCount(departures);
    await expect(page.locator('footer a[href$="#departures"]'), 'footer link to #departures').toHaveCount(departures);

    await page.setViewportSize({ width: 390, height: 800 });
    await page.getByRole('button', { name: 'Menu' }).click();
    for (const id of ['departures', 'visa', 'gallery']) {
      const present = await page.locator(`#${id}`).count();
      await expect(page.locator(`#site-menu a[href$="#${id}"]`), `☰ sheet link to #${id}`).toHaveCount(present ? 1 : 0);
      if (id !== 'gallery') await expect(page.locator(`footer a[href$="#${id}"]`), `footer link to #${id}`).toHaveCount(present ? 1 : 0);
    }
  });
});

test.describe('gallery', () => {
  test('the Facebook page reels play in lazily loaded embedded players near the bottom of the home page', async ({ page }) => {
    await page.goto('/en');
    const reels = page.getByTestId('gallery-reels').locator('iframe');
    await expect(reels).toHaveCount(4);
    const first = reels.first();
    await expect(first).toHaveAttribute('loading', 'lazy');
    await expect(first).toHaveAttribute('title', 'Facebook reel 1');
    const src = new URL((await first.getAttribute('src'))!);
    expect(src.origin + src.pathname).toBe('https://www.facebook.com/plugins/video.php');
    expect(src.searchParams.get('href')).toBe('https://www.facebook.com/reel/1097420422945413');
    await expect(page.locator('#gallery')).toContainText('▶ 104K views');
    await expect(page.locator('#gallery').getByRole('link', { name: /More reels and photos on our Facebook page/ })).toHaveAttribute('href', 'https://www.facebook.com/bhabaghureholidays');
    // Below the reviews and FAQ, above About and the contact block.
    const order = await page.evaluate(() => ['faq', 'gallery', 'about'].map((id) => document.getElementById(id)!.getBoundingClientRect().top));
    expect(order).toEqual([...order].sort((a, b) => a - b));

    // On a phone the players stay full size in a row that scrolls sideways; the page itself never does.
    await page.setViewportSize({ width: 390, height: 800 });
    await expect(first).toHaveJSProperty('offsetWidth', 260);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Menu' }).click();
    await expect(page.locator('#site-menu a[href$="#gallery"]')).toHaveCount(1);
  });
});

test.describe('team page', () => {
  test('the header link opens /ourteam with every member the About section shows, in both languages', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/en');
    const members = await page.locator('#about li').count();
    expect(members).toBeGreaterThan(0);

    const nav = page.locator('header nav');
    await nav.getByRole('link', { name: 'Team', exact: true }).click();
    await expect(page).toHaveURL(/\/en\/ourteam$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Our team' })).toBeVisible();
    await expect(page.locator('#team li')).toHaveCount(members);
    await expect(nav.getByRole('link', { name: 'Team', exact: true })).toHaveAttribute('aria-current', 'page');
    // The contact section is on this page too, so the header's Contact stays here; About goes back home.
    await expect(nav.getByRole('link', { name: 'Contact', exact: true })).toHaveAttribute('href', '#contact');
    await expect(nav.getByRole('link', { name: 'About', exact: true })).toHaveAttribute('href', '/en#about');

    await page.goto('/ourteam');
    await expect(page.getByRole('heading', { level: 1, name: 'আমাদের টিম' })).toBeVisible();
    await expect(page.locator('#team li')).toHaveCount(members);
  });

  test('the About section, the footer and the ☰ sheet lead to it', async ({ page }) => {
    await page.goto('/en');
    await page.locator('#about').getByRole('link', { name: 'See the whole team →' }).click();
    await expect(page).toHaveURL(/\/en\/ourteam$/);
    await expect(page.locator('footer').getByRole('link', { name: 'Our team', exact: true })).toHaveAttribute('href', '/en/ourteam');

    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto('/en');
    await page.getByRole('button', { name: 'Menu' }).click();
    await page.locator('#site-menu').getByRole('link', { name: /^Our team/ }).click();
    await expect(page).toHaveURL(/\/en\/ourteam$/);
  });
});

test.describe('booking', () => {
  test('books, pays through the SSLCommerz stand-in and shows the paid booking read back from the API', async ({ page }) => {
    const inTwoMonths = new Date(Date.now() + 60 * 86_400_000).toISOString().slice(0, 10);
    await page.goto('/en');
    await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('button', { name: 'Book now' }).click();
    const dialog = page.getByRole('dialog', { name: 'Book online' });

    // Step 1: a travel date is required.
    await dialog.getByRole('button', { name: 'Next step →' }).click();
    await expect(dialog.getByText('Please fill in this field.')).toBeVisible();
    await dialog.getByLabel('Departure date').fill(inTwoMonths);
    await dialog.getByRole('button', { name: 'Next step →' }).click();

    // Step 2: two travellers (the search default).
    const travellers = [
      ['MD TANVIR HASAN', 'BW0912345', '14/03/1991', '12/03/2031', '01711223344'],
      ['NUSRAT JAHAN', 'BX4471228', '02/11/1994', '11/07/2029', ''],
    ];

    // No OCR provider is configured for tests: the scan is kept and the traveller is asked to type the details.
    const first = dialog.locator('section').nth(0);
    await first.locator('input[type=file]').setInputFiles({ name: 'passport.png', mimeType: 'image/png', buffer: PNG_1PX });
    await expect(first.getByRole('status')).toHaveText('The passport could not be read automatically. Please type the details below.');

    for (const [i, [name, passport, dob, expiry, phone]] of travellers.entries()) {
      const card = dialog.locator('section').nth(i);
      await card.getByLabel('Name (as on passport)').fill(name);
      await card.getByLabel('Passport number').fill(passport);
      await card.getByLabel('Date of birth').fill(dob);
      await card.getByLabel('Passport expiry').fill(expiry);
      if (phone) await card.getByLabel('WhatsApp number').fill(phone);
    }
    await dialog.getByRole('button', { name: 'Next step →' }).click();

    // Step 3: 75,000 × 2 + 2% service charge = 1,53,000 — the same numbers as the card.
    await expect(dialog.getByRole('heading', { name: 'Review your booking' })).toBeVisible();
    await expect(dialog).toContainText('৳ 1,50,000');
    await expect(dialog).toContainText('৳ 3,000');
    await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,53,000');
    await dialog.getByRole('button', { name: 'Next step →' }).click();
    await expect(dialog.getByText('Please accept the terms to continue.')).toBeVisible();
    await dialog.getByRole('checkbox').check();
    await dialog.getByRole('button', { name: 'Next step →' }).click();

    // Step 4: the total is unchanged; paying creates the booking and opens the gateway.
    await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,53,000');
    await dialog.getByLabel('Nagad').check();
    await dialog.getByRole('button', { name: 'Pay ৳ 1,53,000 with SSLCommerz →' }).click();

    await expect(page.getByText('Fake SSLCommerz')).toBeVisible();
    await expect(page.locator('body')).toContainText('BDT 153,000.00 · nagad');
    await page.getByRole('button', { name: 'Pay', exact: true }).click();

    // Back on the website: the outcome comes from the API, not from the redirect.
    await expect(page).toHaveURL(/\/en\/booking\/BH-\d{4}-\d{3}$/);
    await expect(page.getByRole('heading', { name: 'Payment received — you’re booked!' })).toBeVisible();
    await expect(page.getByRole('link', { name: /^View invoice INV-\d{4}$/ })).toBeVisible();
    await expect(page.locator('main')).not.toContainText('BW0912345');

    // The same page without the token (another browser) shows nothing about the booking.
    const reference = page.url().split('/').pop()!;
    const stranger = await page.context().browser()!.newPage();
    await stranger.goto(`/en/booking/${reference}`);
    await expect(stranger.getByText('Open this page from the link in your booking confirmation.', { exact: false })).toBeVisible();
    await expect(stranger.locator('main')).not.toContainText('MD TANVIR HASAN');
    await stranger.close();
  });

  test('a cancelled payment keeps the booking as an unpaid inquiry that can be paid later', async ({ page }) => {
    const inTwoMonths = new Date(Date.now() + 61 * 86_400_000).toISOString().slice(0, 10);
    await page.goto('/en');
    await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('button', { name: 'Book now' }).click();
    const dialog = page.getByRole('dialog', { name: 'Book online' });
    await dialog.getByLabel('Departure date').fill(inTwoMonths);
    await dialog.getByRole('button', { name: 'Next step →' }).click();
    // The least a customer has to give: the lead traveller's name and WhatsApp number. Everything else waits for staff.
    const lead = dialog.locator('section').nth(0);
    await dialog.getByRole('button', { name: 'Next step →' }).click();
    await expect(lead.getByText('Please fill in this field.')).toHaveCount(2);
    await expect(dialog.locator('section').nth(1).getByText('Please fill in this field.')).toHaveCount(0);
    await lead.getByLabel('Name (as on passport)').fill('RAFIQ ISLAM');
    await lead.getByLabel('WhatsApp number').fill('01811223344');
    await expect(dialog.locator('section').nth(1)).toContainText('Can be added later');
    await expect(dialog.locator('section').nth(1).getByLabel('Passport number (optional)')).toBeVisible();
    await dialog.getByRole('button', { name: 'Next step →' }).click();
    await dialog.getByRole('checkbox').check();
    await dialog.getByRole('button', { name: 'Next step →' }).click();
    await dialog.getByRole('button', { name: /^Pay ৳ 1,53,000 with SSLCommerz/ }).click();

    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'The payment didn’t go through' })).toBeVisible();
    await expect(page.getByText('Your booking is still saved', { exact: false })).toBeVisible();

    // Try again from the booking page.
    await page.getByRole('button', { name: 'Pay with SSLCommerz →' }).click();
    await page.getByRole('button', { name: 'Pay', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Payment received — you’re booked!' })).toBeVisible();
  });
});

/** A valid 1×1 PNG. */
const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
