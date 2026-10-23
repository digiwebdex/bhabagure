import { expect, test, type Page } from '@playwright/test';

import { artisan } from '../../scripts/e2e-api.mjs';

/**
 * docs/visitor-analytics.md on the real API: a visit is one session; each page counts once — a package opened from a
 * card counts as its page, and closing it doesn't count the home page again; opening the booking form is a step, and
 * an enquiry sent from the visit is its conversion. A browser that asks not to be tracked sends nothing.
 */

type Recorded = { pageViews: number; landing: string; events: { type: string; path: string; subject: string | null }[] };

const thai = 'thailand-budget-escape-bangkok-pattaya-coral-island-with';

async function visitId(page: Page): Promise<string> {
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('bh_visit')), { timeout: 15_000 }).not.toBeNull();
  return page.evaluate(() => (JSON.parse(sessionStorage.getItem('bh_visit') ?? '{}') as { id: string }).id);
}

function recorded(token: string): Recorded | null {
  const out = artisan(
    'tinker',
    `--execute=$s = App\\Models\\AnalyticsSession::query()->where('token', '${token}')->first(); echo json_encode($s ? ['pageViews' => $s->page_views, 'landing' => $s->landing_path, 'events' => $s->events()->orderBy('id')->get(['type', 'path', 'subject'])] : null);`,
  )
    .trim()
    .split(/\r?\n/)
    .pop()!;
  return JSON.parse(out) as Recorded | null;
}

test('a visit counts each page once, a package opened from its card as its page, and the enquiry it sent as its conversion', async ({ page }) => {
  await page.goto('/en');
  const token = await visitId(page);
  await expect.poll(() => recorded(token)?.pageViews ?? 0, { timeout: 15_000 }).toBe(1);

  // The package dialog: its own page; closed, back on the home page without counting it again.
  const card = page.locator('#packages article').filter({ hasText: 'THAILAND BUDGET ESCAPE' });
  await card.getByRole('link', { name: /THAILAND BUDGET ESCAPE/ }).click();
  const detail = page.getByRole('dialog', { name: /THAILAND BUDGET ESCAPE/ });
  await expect(detail).toBeVisible();
  await expect.poll(() => recorded(token)?.pageViews ?? 0, { timeout: 15_000 }).toBe(2);
  await detail.getByRole('button', { name: 'Close' }).first().click();
  await expect(detail).toBeHidden();

  // The booking form opened: a step, for that package.
  await card.getByRole('button', { name: 'Book now' }).click();
  await expect(page.getByRole('dialog', { name: 'Book online' })).toBeVisible();
  await page.getByRole('dialog', { name: 'Book online' }).getByRole('button', { name: 'Close' }).first().click();

  // An enquiry from the contact form, accepted by the API: the visit's conversion.
  const form = page.locator('#contact form');
  await form.getByLabel('Name', { exact: true }).fill('E2E Analytics');
  await form.getByLabel('Phone / WhatsApp').fill(`0171${String(Date.now()).slice(-7)}`);
  await form.getByRole('button', { name: 'Send inquiry' }).click();
  await expect(form.getByRole('button', { name: '✓ Sent — we will call you shortly' })).toBeVisible();

  await expect.poll(() => recorded(token)?.events.map((e) => e.type) ?? [], { timeout: 15_000 }).toEqual(['page_view', 'page_view', 'booking_started', 'enquiry']);
  const visit = recorded(token)!;
  expect(visit.landing).toBe('/');
  expect(visit.pageViews).toBe(2);
  expect(visit.events[1]).toMatchObject({ path: `/packages/${thai}`, subject: `package:${thai}` });
  expect(visit.events[2]).toMatchObject({ subject: `package:${thai}` });
  expect(visit.events[3]).toMatchObject({ subject: 'contact' });
});

test('with Google Analytics connected, its tag loads only after Accept, and Cookie settings changes the answer', async ({ page, request }) => {
  const refresh = () => request.post('/api/revalidate', { headers: { Authorization: 'Bearer e2e-revalidate-secret' }, data: { tags: ['settings'] } });
  const setGa4 = (id: string | null) =>
    artisan('tinker', `--execute=$v = App\\Services\\Analytics\\AnalyticsSettings::get(); $v['ga4MeasurementId'] = ${id ? `'${id}'` : 'null'}; App\\Models\\SiteSetting::query()->updateOrCreate(['key' => 'analytics'], ['value' => $v]); echo 'ok';`);
  // Google's tag is answered here: the test never reaches Google, and sees each request for it.
  const tagRequests: string[] = [];
  await page.route('https://www.googletagmanager.com/**', (route) => {
    tagRequests.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'text/javascript', body: '' });
  });

  // Not connected: no banner, no tag.
  await page.goto('/en');
  await expect(page.getByTestId('cookie-consent')).toHaveCount(0);

  setGa4('G-E2ETEST123');
  try {
    expect((await refresh()).status()).toBe(200);
    await expect.poll(async () => {
      await page.goto('/en');
      return page.getByTestId('cookie-consent').count();
    }, { timeout: 20_000 }).toBe(1);

    // Decline: nothing loads, and the answer is remembered.
    await page.getByTestId('cookie-consent').getByRole('button', { name: 'Decline' }).click();
    await expect(page.getByTestId('cookie-consent')).toHaveCount(0);
    await page.reload();
    await page.waitForTimeout(1000);
    await expect(page.getByTestId('cookie-consent')).toHaveCount(0);
    expect(tagRequests).toEqual([]);

    // Cookie settings in the footer asks again; Accept loads GA4 for this Measurement ID and counts the page.
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByTestId('cookie-consent').getByRole('button', { name: 'Accept' }).click();
    await expect.poll(() => tagRequests.length).toBe(1);
    expect(tagRequests[0]).toContain('id=G-E2ETEST123');
    const sent = await page.evaluate(() => JSON.stringify((window as unknown as { dataLayer: unknown[] }).dataLayer));
    expect(sent).toContain('"config","G-E2ETEST123"');
    expect(sent).toContain('"page_view"');
    // Accepted before: the next visit loads it straight away, without asking.
    await page.reload();
    await expect.poll(() => tagRequests.length).toBe(2);
    await expect(page.getByTestId('cookie-consent')).toHaveCount(0);
  } finally {
    setGa4(null);
    await refresh();
  }
});

test('a browser that asks not to be tracked sends nothing, and the website works as before', async ({ browser }) => {
  const context = await browser.newContext();
  await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true }));
  const page = await context.newPage();
  const beacons: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/analytics/events')) beacons.push(request.url());
  });
  await page.goto('/en');
  await page.locator('#packages article').first().getByRole('button', { name: 'Book now' }).click();
  await expect(page.getByRole('dialog', { name: 'Book online' })).toBeVisible();
  await page.waitForTimeout(1500);
  expect(beacons).toEqual([]);
  expect(await page.evaluate(() => sessionStorage.getItem('bh_visit'))).toBeNull();
  await context.close();
});
