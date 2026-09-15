import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { API_DIR, artisan, E2E_API_URL } from '../../scripts/e2e-api.mjs';

import { PORTAL_URL } from './hosts';

/**
 * The customer portal on live data (docs/phase-6-customer-portal.md §6): sign-in by code, trips, paying the balance and
 * coming back signed in, documents reviewed by staff, support replies, Bangla by default and phone width.
 */

let phoneSequence = 0;
const uniquePhone = () => `0171${String(Date.now() + phoneSequence++).slice(-7)}`;

/** The newest code the SMS stand-in "sent" to this number (api/storage/logs/sms-fake.log). */
function lastCode(phone: string): string {
  const entries = readFileSync(resolve(API_DIR, 'storage/logs/sms-fake.log'), 'utf8')
    .split(/\r?\n(?=\[\d{4}-\d{2}-\d{2})/)
    .filter((entry) => entry.includes(`SMS (fake) to 88${phone}`));
  const code = /(?<!\d)(\d{6})(?!\d)/.exec(entries.at(-1) ?? '')?.[1];
  if (!code) throw new Error(`No code was sent to ${phone}`);
  return code;
}

async function book(request: APIRequestContext, phone: string, name: string): Promise<string> {
  const created = await request.post(`${E2E_API_URL}/api/v1/public/bookings`, {
    headers: { Accept: 'application/json' },
    data: {
      package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights',
      travel_date: new Date(Date.now() + 45 * 86_400_000).toISOString().slice(0, 10),
      pax: 1,
      room: 'twin',
      addons: [],
      travellers: [{ name, passport_number: 'BW0812345', date_of_birth: '1993-02-11', passport_expiry: '2031-03-12', phone }],
      expected_total: 76500,
      terms_accepted: true,
      locale: 'en',
    },
  });
  expect(created.status()).toBe(201);
  return (await created.json()).data.reference as string;
}

async function staffHeaders(request: APIRequestContext): Promise<Record<string, string>> {
  const password = 'e2e-portal-staff-pass';
  artisan('tinker', `--execute=App\\Models\\Staff::query()->updateOrCreate(['email' => 'web.portal@e2e.test'], ['employee_code' => 'E2E-PTL', 'name' => 'Portal Reviewer', 'password' => '${password}', 'status' => 'active', 'must_change_password' => false])->syncRoles(['admin']);`);
  const login = await request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { data: { email: 'web.portal@e2e.test', password } });
  return { Authorization: `Bearer ${(await login.json()).access_token}`, Accept: 'application/json' };
}

/** Phone → code → signed in, on the portal's own sign-in screen. */
async function signIn(page: Page, phone: string, path = '/en') {
  await page.goto(`${PORTAL_URL}${path}`);
  const mobile = path.startsWith('/en') ? 'Mobile number' : 'মোবাইল নম্বর';
  await page.getByLabel(mobile).fill(phone);
  await page.getByRole('button', { name: path.startsWith('/en') ? 'Send code' : 'কোড পাঠান' }).click();
  await expect(page.getByLabel(path.startsWith('/en') ? 'Code' : 'কোড', { exact: true })).toBeVisible();
  await page.getByLabel(path.startsWith('/en') ? 'Code' : 'কোড', { exact: true }).fill(lastCode(phone));
  await page.getByRole('button', { name: path.startsWith('/en') ? 'Sign in' : 'সাইন ইন করুন' }).click();
}

test.describe('customer portal', () => {
  test('signs in with a code, pays the balance online and comes back to the portal still signed in', async ({ page, request }) => {
    const phone = uniquePhone();
    const reference = await book(request, phone, 'RAIHAN KABIR');

    await page.goto(`${PORTAL_URL}/en`);
    await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible();
    await signIn(page, phone);

    const nav = page.getByRole('navigation', { name: 'My account' });
    await expect(nav.getByRole('link', { name: 'My trips' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByText('Next trip')).toBeVisible();
    await expect(page.locator('article').filter({ hasText: reference })).toContainText('Awaiting payment');
    await expect(page.getByText('Balance still to pay')).toBeVisible();

    // A reload restores the session from the refresh cookie: same site as the API.
    await page.reload();
    await expect(page.locator('article').filter({ hasText: reference })).toBeVisible();

    await page.locator('article').filter({ hasText: reference }).getByRole('link', { name: 'Details' }).click();
    await expect(page.getByRole('heading', { name: /NEPAL MUSTANG/i })).toBeVisible();
    await page.getByLabel('bKash').check();
    await page.getByRole('button', { name: 'Pay ৳ 76,500' }).click();
    await expect(page.getByText('Fake SSLCommerz')).toBeVisible();
    await page.getByRole('button', { name: 'Pay', exact: true }).click();

    // SSLCommerz sends the customer back to the portal trip, where the session is restored again.
    await expect(page).toHaveURL(`${PORTAL_URL}/en/trips/${reference}`);
    await expect(page.getByText('Confirmed', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^Pay ৳/ })).toHaveCount(0);

    await nav.getByRole('link', { name: 'Payments' }).click();
    await expect(page.getByText('Total paid')).toBeVisible();
    await expect(page.locator('section').filter({ hasText: 'Payment history' })).toContainText('৳ 76,500');
    await expect(page.locator('section').filter({ hasText: 'Payment history' })).toContainText('Online payment');

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Sign in to your account' })).toBeVisible();
  });

  test('a photo uploaded in the portal is verified by staff, and a support request gets its reply', async ({ page, request }) => {
    const phone = uniquePhone();
    const reference = await book(request, phone, 'SADIA RAHMAN');
    const headers = await staffHeaders(request);
    await signIn(page, phone);
    const nav = page.getByRole('navigation', { name: 'My account' });

    await nav.getByRole('link', { name: 'Documents' }).click();
    await expect(page.getByRole('heading', { name: 'Documents' })).toBeVisible();
    await page.getByLabel('Upload Photo (2x2)').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: PNG_1PX });
    await expect(page.getByText('our team is checking it')).toBeVisible();

    const queue = await (await request.get(`${E2E_API_URL}/api/v1/admin/document-reviews`, { headers })).json();
    const upload = (queue.data as { id: number; kind: string; booking: { reference: string } }[]).find((row) => row.booking.reference === reference && row.kind === 'photo');
    expect(upload).toBeTruthy();
    expect((await request.post(`${E2E_API_URL}/api/v1/admin/traveller-documents/${upload!.id}/review`, { headers, data: { decision: 'verified' } })).status()).toBe(200);
    await page.reload();
    await expect(page.getByText('Verified by our team')).toBeVisible();

    await nav.getByRole('link', { name: 'Support' }).click();
    await page.getByLabel('Booking').selectOption(reference);
    await page.getByLabel('Subject').fill('Window seat on the Jomsom flight');
    await page.getByLabel('Details').fill('Could we have a window seat, please?');
    await page.getByRole('button', { name: 'Send request' }).click();
    await expect(page.getByRole('status')).toContainText(/Request ST-\d{4} sent/);

    const tickets = await (await request.get(`${E2E_API_URL}/api/v1/admin/support-tickets?search=${encodeURIComponent('Window seat on the Jomsom flight')}`, { headers })).json();
    const ticket = tickets.data[0] as { id: number; number: string };
    expect((await request.post(`${E2E_API_URL}/api/v1/admin/support-tickets/${ticket.id}/replies`, { headers, data: { body: 'Done — seat 12A is yours.' } })).status()).toBe(200);

    await page.reload();
    await page.getByRole('link', { name: /Window seat on the Jomsom flight/ }).click();
    await expect(page.getByText('Done — seat 12A is yours.')).toBeVisible();
    await expect(page.getByText('Portal Reviewer')).toHaveCount(0);
    await expect(page.getByText('Portal · Bhabaghure')).toBeVisible();
  });

  test('is Bangla by default and fits a phone screen', async ({ page, request }) => {
    const phone = uniquePhone();
    await book(request, phone, 'NAFIS IQBAL');
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto(`${PORTAL_URL}/`);
    await expect(page.getByRole('heading', { name: 'আপনার অ্যাকাউন্টে ঢুকুন' })).toBeVisible();
    await signIn(page, phone, '/');
    await expect(page.getByRole('link', { name: 'আমার যাত্রা' })).toBeVisible();
    await expect(page.getByText('আসন্ন যাত্রা')).toBeVisible();
    await expect(page.locator('main')).toContainText('৳ ৭৬,৫০০');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe('website sign-in', () => {
  test('a new number signs in with a code, gives a name, and the header opens the portal', async ({ page }) => {
    const phone = uniquePhone();
    await page.goto('/en');
    await page.getByRole('button', { name: 'Sign in' }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Mobile number').fill('0171');
    await dialog.getByRole('button', { name: 'Send code' }).click();
    await expect(dialog.getByText('Enter a valid mobile number (e.g. 01XXXXXXXXX).')).toBeVisible();

    await dialog.getByLabel('Mobile number').fill(phone);
    await dialog.getByRole('button', { name: 'Send code' }).click();
    await expect(dialog.getByText(/We sent a 6-digit code/)).toBeVisible();
    await dialog.getByLabel('Code', { exact: true }).fill(lastCode(phone) === '000000' ? '111111' : '000000');
    await dialog.getByRole('button', { name: 'Sign in' }).click();
    await expect(dialog.getByRole('alert')).toHaveText('That code is wrong or has expired. Ask for a new one.');

    await dialog.getByLabel('Code', { exact: true }).fill(lastCode(phone));
    await dialog.getByRole('button', { name: 'Sign in' }).click();
    // Nothing said whether the number was known until the code proved it.
    await expect(dialog.getByText('Tell us your name to finish signing in.')).toBeVisible();
    await dialog.getByLabel('Full name as on passport').fill('E2E Customer');
    await dialog.getByRole('button', { name: 'Finish' }).click();
    await expect(dialog.getByText('Welcome, E2E Customer')).toBeVisible();
    await expect(dialog.getByRole('link', { name: 'Open my portal →' })).toHaveAttribute('href', PORTAL_URL);

    await dialog.getByRole('button', { name: 'Close' }).click();
    await expect(page.getByRole('link', { name: 'EC My account' })).toHaveAttribute('href', PORTAL_URL);
  });

  test('a brochure download asks a visitor to sign in, then downloads what they chose, and every download is logged', async ({ page, request }) => {
    const slug = 'nepal-mustang-adventure-tour-8-days-7-nights';
    const phone = uniquePhone();
    await page.goto(`/en/packages/${slug}`);
    await page.getByRole('button', { name: 'Travellers +' }).click();

    await page.getByTestId('download-brochure').click();
    const dialog = page.getByRole('dialog', { name: 'Sign in to download' });
    await expect(dialog).toContainText('Brochures and visa requirements are for signed-in customers.');
    await dialog.getByLabel('Mobile number').fill(phone);
    await dialog.getByRole('button', { name: 'Send code' }).click();
    await expect(dialog.getByText(/We sent a 6-digit code/)).toBeVisible();
    await dialog.getByLabel('Code', { exact: true }).fill(lastCode(phone));
    await dialog.getByRole('button', { name: 'Sign in' }).click();
    await dialog.getByLabel('Full name as on passport').fill('Brochure Reader');

    // Signed in, the download starts by itself: the PDF, named for the package.
    const first = page.waitForEvent('download', { timeout: 60_000 });
    await dialog.getByRole('button', { name: 'Finish' }).click();
    const file = await first;
    expect(file.suggestedFilename()).toBe(`bhabaghure-${slug}.pdf`);
    expect(readFileSync((await file.path())!).subarray(0, 5).toString()).toBe('%PDF-');
    await expect(dialog.getByRole('status')).toContainText('You’re signed in — your download has started.');
    await dialog.getByRole('button', { name: 'Close' }).click();

    // Now signed in, the button downloads straight away.
    const second = page.waitForEvent('download', { timeout: 60_000 });
    await page.getByTestId('download-brochure').click();
    await second;
    await expect(page.getByRole('dialog')).toHaveCount(0);

    // Both are on the Downloads screen, with the traveller count chosen on the page.
    const headers = await staffHeaders(request);
    artisan('tinker', `--execute=App\\Models\\Staff::query()->where('email', 'web.portal@e2e.test')->sole()->givePermissionTo('downloads.view'); echo 'ok';`);
    const log = await (await request.get(`${E2E_API_URL}/api/v1/admin/downloads?search=${phone}`, { headers })).json();
    expect(log.meta.total).toBe(2);
    expect(log.data[0]).toMatchObject({ kind: 'package', slug, pax: 3, customer: { name: 'Brochure Reader' }, in_progress: { booking: false, quotation: false } });
  });
});

/** A valid 1×1 PNG. */
const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
