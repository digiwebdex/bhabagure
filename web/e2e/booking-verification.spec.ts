import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { API_DIR, artisan } from '../../scripts/e2e-api.mjs';

/**
 * docs/booking-phone-verification.md: with the check on (Admin → Site settings → Website booking), a code goes to the lead
 * traveller's mobile and the booking is saved only with it; then the customer goes on to pay. Codes come from the SMS
 * stand-in's log (api/storage/logs/sms-fake.log). Mustang for two: ৳1,53,000.
 */

const uniquePhone = () => `0171${String(Date.now()).slice(-7)}`;

/** The newest code the SMS stand-in "sent" to this number (0171XXXXXXX). */
function lastCode(phone: string): string {
  const entries = readFileSync(resolve(API_DIR, 'storage/logs/sms-fake.log'), 'utf8')
    .split(/\r?\n(?=\[\d{4}-\d{2}-\d{2})/)
    .filter((entry) => entry.includes(`SMS (fake) to 88${phone}`));
  const code = /(?<!\d)(\d{6})(?!\d)/.exec(entries.at(-1) ?? '')?.[1];
  if (!code) throw new Error(`No code was sent to ${phone}`);
  return code;
}

const setCheck = (on: boolean) => artisan('tinker', `--execute=App\\Models\\SiteSetting::query()->updateOrCreate(['key' => 'booking'], ['value' => ['verifyPhone' => ${on}]]); echo 'ok';`);

/** Bookings saved for this lead number, and whether the first was verified. */
function saved(phone: string): { count: number; verified: boolean } {
  const out = artisan('tinker', `--execute=$b = App\\Models\\Booking::query()->whereHas('travellers', fn ($q) => $q->where('phone', '88${phone}')); echo json_encode(['count' => (clone $b)->count(), 'verified' => (clone $b)->whereNotNull('phone_verified_at')->exists()]);`);
  return JSON.parse(out.trim().split(/\r?\n/).pop()!);
}

/** The Mustang booking form filled up to its last step, with the least it needs. */
async function toPayment(page: Page, phone: string) {
  await page.goto('/en');
  await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('button', { name: 'Book now' }).click();
  const dialog = page.getByRole('dialog', { name: 'Book online' });
  await dialog.getByLabel('Departure date').fill(new Date(Date.now() + 64 * 86_400_000).toISOString().slice(0, 10));
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  const lead = dialog.locator('section').nth(0);
  await lead.getByLabel('Name (as on passport)').fill('CODE CUSTOMER');
  await lead.getByLabel('WhatsApp number').fill(phone);
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  return dialog;
}

test('with the check on, the booking is saved only with the code sent to the lead’s mobile, and then goes on to payment', async ({ page, request }) => {
  // The website caches the pricing (with verifyPhone) under the settings tag.
  const refresh = () => request.post('/api/revalidate', { headers: { Authorization: 'Bearer e2e-revalidate-secret' }, data: { tags: ['settings'] } });
  setCheck(true);
  try {
    // A page made before the switch went on: the API asks for the code, and the form sends it then.
    const phone = uniquePhone();
    const dialog = await toPayment(page, phone);
    const pay = dialog.getByRole('button', { name: 'Pay ৳ 1,53,000 with SSLCommerz →' });
    await pay.click();
    const box = dialog.getByTestId('booking-verify');
    await expect(box).toContainText(`We sent a 6-digit code to ${phone} by SMS.`);
    await expect(box).toContainText(/New code in \d+s/);
    expect(saved(phone).count).toBe(0);

    await pay.click();
    await expect(box.getByRole('alert')).toHaveText('Enter the 6-digit code we sent to your mobile.');
    const code = lastCode(phone);
    await box.getByLabel('Booking code').fill(code === '123456' ? '654321' : '123456');
    await pay.click();
    await expect(box.getByRole('alert')).toHaveText('That code is wrong or has expired. Ask for a new one.');
    expect(saved(phone).count).toBe(0);

    // The right code: saved, verified, and on to the payment page.
    await box.getByLabel('Booking code').fill(code);
    await pay.click();
    await expect(page.getByText('Fake SSLCommerz')).toBeVisible();
    expect(saved(phone)).toEqual({ count: 1, verified: true });

    // Once the site knows, the first click sends the code. "Change number" goes back to the travellers; coming back, the
    // code sent a moment ago still works.
    expect((await refresh()).status()).toBe(200);
    const phone2 = uniquePhone();
    const again = await toPayment(page, phone2);
    const pay2 = again.getByRole('button', { name: 'Pay ৳ 1,53,000 with SSLCommerz →' });
    await pay2.click();
    await expect(again.getByTestId('booking-verify')).toContainText(`We sent a 6-digit code to ${phone2} by SMS.`);
    await again.getByRole('button', { name: 'Change number' }).click();
    await expect(again.getByRole('heading', { name: 'Traveller details' })).toBeVisible();
    await again.getByRole('button', { name: 'Next step →' }).click();
    await again.getByRole('button', { name: 'Next step →' }).click();
    await pay2.click();
    await expect(again.getByTestId('booking-verify')).toContainText('A code was sent a moment ago — enter that one');
    await again.getByTestId('booking-verify').getByLabel('Booking code').fill(lastCode(phone2));
    await pay2.click();
    await expect(page.getByText('Fake SSLCommerz')).toBeVisible();
    expect(saved(phone2)).toEqual({ count: 1, verified: true });
  } finally {
    setCheck(false);
    expect((await refresh()).status()).toBe(200);
    // Rebuild the refreshed page here, so the next test's requests don't queue behind it on the one-at-a-time e2e API.
    await page.goto('/en');
  }
});
