import { expect, test, type Page } from '@playwright/test';

import { artisan } from '../../scripts/e2e-api.mjs';

/**
 * docs/coupons.md: a coupon on the website's booking form. The API works the discount out; the form shows Subtotal, the
 * coupon's line, the service charge on what is left, and the total to pay. Mustang for two: ৳1,50,000 + 2% = ৳1,53,000;
 * with 10% off, ৳1,35,000 + ৳2,700 = ৳1,37,700.
 */

/** Coupons straight into the e2e database (the admin screens have their own tests). No value may hold a single quote. */
function coupons(rows: Record<string, string | number | boolean>[]) {
  const php = rows.map((row) => `App\\Models\\Coupon::query()->firstOrCreate(['code' => '${row.code}'], json_decode('${JSON.stringify(row)}', true));`).join(' ');
  artisan('tinker', `--execute=${php} echo 'ok';`);
}

/** Opens the Mustang booking form and fills steps 1 and 2 with the least the form needs. */
async function toReview(page: Page, phone: string) {
  await page.goto('/en');
  await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('button', { name: 'Book now' }).click();
  const dialog = page.getByRole('dialog', { name: 'Book online' });
  await dialog.getByLabel('Departure date').fill(new Date(Date.now() + 62 * 86_400_000).toISOString().slice(0, 10));
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  const lead = dialog.locator('section').nth(0);
  await lead.getByLabel('Name (as on passport)').fill('COUPON CUSTOMER');
  await lead.getByLabel('WhatsApp number').fill(phone);
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  await expect(dialog.getByRole('heading', { name: 'Review your booking' })).toBeVisible();
  return dialog;
}

test('a coupon comes off before the service charge, a wrong or someone else’s code is refused, and the booking keeps it', async ({ page }) => {
  coupons([
    { code: 'WEB-TRAVEL10', name: 'Website promotion', kind: 'public', discount_type: 'percent', discount_value: 10, applies_to: 'all', is_active: true },
    { code: 'WEB-PASS500', name: 'Loyal traveller', kind: 'passport', passport_number: 'B12345678', discount_type: 'fixed', discount_value: 500, applies_to: 'all', is_active: true },
  ]);
  const dialog = await toReview(page, '01711998877');
  const total = dialog.getByTestId('booking-total');
  await expect(total).toHaveText('৳ 1,53,000');

  const code = dialog.getByLabel('Have a coupon code?');
  const apply = dialog.getByRole('button', { name: 'Apply' });
  await apply.click();
  await expect(dialog.getByRole('alert')).toHaveText('Type your coupon code first.');
  await code.fill('nope');
  await apply.click();
  await expect(dialog.getByRole('alert')).toHaveText("This coupon code isn't valid. Check it and try again.");
  // A passport coupon needs its holder on the booking — and never says whose passport it is.
  await code.fill('web-pass500');
  await apply.click();
  await expect(dialog.getByRole('alert')).toContainText('This coupon is for a particular passport holder.');
  await expect(total).toHaveText('৳ 1,53,000');

  await code.fill('web-travel10');
  await apply.click();
  await expect(dialog.getByTestId('coupon-applied')).toContainText('WEB-TRAVEL10 · Coupon applied — you save ৳ 15,000.');
  await expect(dialog.getByTestId('booking-coupon-line')).toHaveText(/Coupon discount \(WEB-TRAVEL10\)\s*− ৳ 15,000/);
  await expect(dialog).toContainText('Subtotal');
  await expect(dialog).toContainText('৳ 2,700');
  await expect(total).toHaveText('৳ 1,37,700');

  // Removed: the price as before; applied again.
  await dialog.getByRole('button', { name: 'Remove' }).click();
  await expect(total).toHaveText('৳ 1,53,000');
  await expect(dialog.getByTestId('booking-coupon-line')).toHaveCount(0);
  await dialog.getByLabel('Have a coupon code?').fill('WEB-TRAVEL10');
  await dialog.getByRole('button', { name: 'Apply' }).click();
  await expect(total).toHaveText('৳ 1,37,700');

  // Back to the travellers: a change checks the coupon again, and it still holds.
  await dialog.getByRole('button', { name: '← Back' }).click();
  await dialog.locator('section').nth(1).getByLabel('Passport number (optional)').fill('B98765432');
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  await expect(dialog.getByTestId('coupon-applied')).toContainText('WEB-TRAVEL10');
  await expect(total).toHaveText('৳ 1,37,700');

  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Next step →' }).click();
  await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,37,700');
  await dialog.getByRole('button', { name: 'Pay ৳ 1,37,700 with SSLCommerz →' }).click();

  // The gateway is asked for the discounted total, and the booking page shows the coupon as its own line.
  await expect(page.getByText('Fake SSLCommerz')).toBeVisible();
  await expect(page.locator('body')).toContainText('BDT 137,700.00');
  await page.getByRole('button', { name: 'Pay', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Payment received — you’re booked!' })).toBeVisible();
  await expect(page.locator('main')).toContainText('Coupon discount (WEB-TRAVEL10)');
  await expect(page.locator('main')).toContainText('− ৳ 15,000');
  await expect(page.locator('main')).toContainText('৳ 1,37,700');
});

test('a coupon that stops working before the booking is made is taken off, and the customer books at the full price', async ({ page }) => {
  coupons([{ code: 'WEB-FLASH', name: 'Flash sale', kind: 'public', discount_type: 'fixed', discount_value: 3000, applies_to: 'all', is_active: true }]);
  const dialog = await toReview(page, '01811998877');
  await dialog.getByLabel('Have a coupon code?').fill('WEB-FLASH');
  await dialog.getByRole('button', { name: 'Apply' }).click();
  // 1,47,000 + 2,940.
  await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,49,940');
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('button', { name: 'Next step →' }).click();

  // Switched off in the office while the customer was on the last step.
  artisan('tinker', `--execute=App\\Models\\Coupon::query()->where('code', 'WEB-FLASH')->update(['is_active' => false]); echo 'ok';`);
  await dialog.getByRole('button', { name: 'Pay ৳ 1,49,940 with SSLCommerz →' }).click();
  await expect(dialog.getByRole('alert')).toContainText("This coupon isn't active.");
  await expect(dialog.getByRole('alert')).toContainText('the total is now ৳ 1,53,000');
  await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,53,000');

  await dialog.getByRole('button', { name: 'Pay ৳ 1,53,000 with SSLCommerz →' }).click();
  await expect(page.getByText('Fake SSLCommerz')).toBeVisible();
  await expect(page.locator('body')).toContainText('BDT 153,000.00');
});
