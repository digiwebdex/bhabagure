import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { API_DIR, artisan, E2E_API_URL } from '../../scripts/e2e-api.mjs';

/** Behaviour that only exists on live data: real form submissions, account rules and CMS-driven refreshes. */

const uniquePhone = () => `0171${String(Date.now()).slice(-7)}`;

test.describe('forms on the live API', () => {
  test('the contact form is accepted by the API', async ({ page }) => {
    await page.goto('/en');
    const form = page.locator('#contact form');
    await form.getByLabel('Name', { exact: true }).fill('E2E Visitor');
    await form.getByLabel('Phone / WhatsApp').fill(uniquePhone());
    await form.getByRole('button', { name: 'Send inquiry' }).click();
    await expect(form.getByRole('button', { name: '✓ Sent — we will call you shortly' })).toBeVisible();
  });

  test('the hotel tab sends a quotation request that lands in the Hotel requests queue', async ({ page }) => {
    const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
    const phone = uniquePhone();
    await page.goto('/en');
    const tabs = page.locator('#search').getByRole('tab');
    await expect(tabs).toHaveText(['Find a tour package', 'Air ticket quote', 'Hotel quotation']);
    await tabs.filter({ hasText: 'Hotel quotation' }).click();

    const form = page.getByRole('form', { name: 'Hotel quotation request' });
    await form.getByLabel('Location').fill("Cox's Bazar");
    await form.getByLabel('Check-in').fill(inDays(30));
    await form.getByLabel('Check-out').fill(inDays(29));
    await form.getByLabel('Hotel category').selectOption('4');
    await form.getByLabel('Guests').selectOption('3');
    await form.getByLabel('Note (optional)').fill('Sea view, near Kolatoli');
    await form.getByPlaceholder('Your name').fill('E2E Hotel Guest');
    await form.getByPlaceholder('WhatsApp number').fill(phone);
    await form.getByRole('button', { name: 'Request quotation' }).click();
    await expect(form.getByText('The check-out date must be after the check-in date.')).toBeVisible();

    await form.getByLabel('Check-out').fill(inDays(33));
    await expect(form.getByText('Check-out · 3 nights')).toBeVisible();
    await form.getByRole('button', { name: 'Request quotation' }).click();
    await expect(form.getByRole('status')).toContainText('Quotation request sent');

    // Stored as a hotel request, the customer a lead.
    const stored = artisan('tinker', `--execute=echo json_encode(App\\Models\\Inquiry::query()->where('phone', '88${phone}')->first(['type', 'pax', 'details']));`).trim().split(/\r?\n/).pop()!;
    const inquiry = JSON.parse(stored) as { type: string; pax: number; details: Record<string, string | null> };
    expect([inquiry.type, inquiry.pax, inquiry.details.location, inquiry.details.hotelCategory, inquiry.details.checkOut]).toEqual(['hotel_quote', 3, "Cox's Bazar", '4', inDays(33)]);
  });

  test('newsletter sign-up, then the signed unsubscribe link works without signing in', async ({ page }) => {
    const email = `reader-${Date.now()}@e2e.test`;
    await page.goto('/en');
    await page.getByPlaceholder('Your email').fill(email);
    await page.getByRole('button', { name: 'Subscribe' }).click();
    await expect(page.getByText('Thank you — you are on the list.')).toBeVisible();

    const url = artisan('tinker', `--execute=echo App\\Support\\NewsletterUnsubscribeToken::url(App\\Models\\NewsletterSubscriber::query()->where('email', '${email}')->firstOrFail());`)
      .trim()
      .split(/\r?\n/)
      .pop()!;
    const path = new URL(url).pathname;

    await page.goto(`/en${path}`);
    await expect(page.getByText(/^Stop sending newsletters to re\*+@e2e\.test\?$/)).toBeVisible();
    await page.getByRole('button', { name: 'Unsubscribe' }).click();
    await expect(page.getByText('Done — you will not receive our newsletter any more.')).toBeVisible();

    // A tampered link is refused.
    await page.goto(`/en${path.slice(0, -3)}abc`);
    await expect(page.getByText('This unsubscribe link is not valid.', { exact: false })).toBeVisible();
  });
});

test.describe('CMS to website', () => {
  test('the FAQ quotes the single-room supplement from the Pricing screen', async ({ page }) => {
    await page.goto('/en');
    await expect(page.locator('#faq')).toContainText('a single room adds a 12% supplement');
  });

  test('an online payment charge set on the Pricing screen is its own line, and the gateway charges exactly the reviewed total', async ({ page, request }) => {
    const password = 'e2e-website-pricing-pass';
    artisan('tinker', `--execute=App\\Models\\Staff::query()->updateOrCreate(['email' => 'web.pricing@e2e.test'], ['employee_code' => 'E2E-PRC', 'name' => 'Pricing editor', 'password' => '${password}', 'status' => 'active', 'must_change_password' => false])->syncRoles(['tour_operator']);`);
    const login = await request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { data: { email: 'web.pricing@e2e.test', password } });
    const headers = { Authorization: `Bearer ${(await login.json()).access_token}`, Accept: 'application/json' };
    const pricing = (await (await request.get(`${E2E_API_URL}/api/v1/admin/pricing`, { headers })).json()).data;
    const setCharge = (percent: number) => request.put(`${E2E_API_URL}/api/v1/admin/pricing`, { headers, data: { ...pricing, online_payment_charge_percent: percent } });

    try {
      expect((await setCharge(2.5)).status()).toBe(200);
      await expect
        .poll(async () => (await (await page.request.get('/en')).text()).match(/onlinePaymentChargePercent\\?":2\.5/) !== null, { timeout: 20_000 })
        .toBe(true);

      await page.goto('/en');
      await page.locator('#packages article').filter({ hasText: 'NEPAL MUSTANG' }).getByRole('button', { name: 'Book now' }).click();
      const dialog = page.getByRole('dialog', { name: 'Book online' });
      await dialog.getByLabel('Departure date').fill(new Date(Date.now() + 62 * 86_400_000).toISOString().slice(0, 10));
      await dialog.getByRole('button', { name: 'Next step →' }).click();
      for (const [i, name] of ['KAMRUL HASAN', 'LIPI BEGUM'].entries()) {
        const card = dialog.locator('section').nth(i);
        await card.getByLabel('Name (as on passport)').fill(name);
        await card.getByLabel('Passport number').fill(i === 0 ? 'BW0712345' : 'BX0712345');
        await card.getByLabel('Date of birth').fill('14/03/1991');
        await card.getByLabel('Passport expiry').fill('12/03/2031');
        if (i === 0) await card.getByLabel('WhatsApp number').fill('01911223344');
      }
      await dialog.getByRole('button', { name: 'Next step →' }).click();

      // Review: 1,53,000 + the online payment charge (2.5% = 3,825) = 1,56,825, before the customer commits.
      await expect(dialog.getByText('Online payment charge (2.5%)')).toBeVisible();
      await expect(dialog).toContainText('৳ 3,825');
      await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,56,825');
      await dialog.getByRole('checkbox').check();
      await dialog.getByRole('button', { name: 'Next step →' }).click();

      await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,56,825');
      await dialog.getByRole('button', { name: 'Pay ৳ 1,56,825 with SSLCommerz →' }).click();

      // The gateway is asked for exactly the reviewed total — nothing added in between.
      await expect(page.locator('body')).toContainText('BDT 156,825.00');
      await page.getByRole('button', { name: 'Pay', exact: true }).click();
      await expect(page.getByRole('heading', { name: 'Payment received — you’re booked!' })).toBeVisible();
      // The booking itself is still 1,53,000: the charge is not booking money.
      await expect(page.locator('main')).toContainText('৳ 1,53,000');
    } finally {
      await setCharge(0);
    }
  });

  test('the notifications number from site settings is published beside the main number, and automated WhatsApp messages name the sender', async ({ page, request }) => {
    const password = 'e2e-website-settings-pass';
    artisan('tinker', `--execute=App\\Models\\Staff::query()->updateOrCreate(['email' => 'web.settings@e2e.test'], ['employee_code' => 'E2E-SET', 'name' => 'Settings editor', 'password' => '${password}', 'status' => 'active', 'must_change_password' => false])->syncRoles(['admin']);`);
    const login = await request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { data: { email: 'web.settings@e2e.test', password } });
    const headers = { Authorization: `Bearer ${(await login.json()).access_token}`, Accept: 'application/json' };
    const contact = (await (await request.get(`${E2E_API_URL}/api/v1/admin/settings`, { headers })).json()).data.contact;
    const saveContact = (notificationsWhatsapp: string) => request.put(`${E2E_API_URL}/api/v1/admin/settings/contact`, { headers, data: { value: { ...contact, notificationsWhatsapp } } });

    try {
      expect((await saveContact('+8801911000111')).status()).toBe(200);
      await expect
        .poll(async () => {
          await page.goto('/en');
          return page.locator('#contact').innerText();
        }, { timeout: 20_000 })
        .toContain('+880 1911 000111');
      await expect(page.locator('#contact')).toContainText('Automated WhatsApp messages about bookings come only from this number.');
      await expect(page.locator('footer')).toContainText('Notifications +880 1911 000111');

      await page.goto('/');
      await expect(page.locator('#contact')).toContainText('নোটিফিকেশন নম্বর · Notifications');
      await expect(page.locator('#contact')).toContainText('+৮৮০ ১৯১১ ০০০১১১');

      // The booking page names both numbers; the booking's WhatsApp message opens with who it is from.
      const phone = uniquePhone();
      const created = await request.post(`${E2E_API_URL}/api/v1/public/bookings`, {
        headers: { Accept: 'application/json' },
        data: {
          package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights',
          travel_date: new Date(Date.now() + 75 * 86_400_000).toISOString().slice(0, 10),
          pax: 1,
          room: 'twin',
          addons: [],
          travellers: [{ name: 'RUMANA ISLAM', passport_number: 'BW0812345', date_of_birth: '1993-02-11', passport_expiry: '2031-03-12', phone }],
          expected_total: 76500,
          terms_accepted: true,
          locale: 'en',
        },
      });
      expect(created.status()).toBe(201);
      const { reference, accessToken } = (await created.json()).data as { reference: string; accessToken: string };
      await page.goto(`/en/booking/${reference}#t=${accessToken}`);
      await expect(page.getByTestId('notifications-number')).toHaveText(
        'Automated WhatsApp messages about this booking come only from our notifications number +880 1911 000111. Our main number is +880 1743 939300.',
      );
      const sent = readFileSync(resolve(API_DIR, 'storage/logs/whatsapp-fake.log'), 'utf8')
        .split(/\r?\n(?=\[\d{4}-\d{2}-\d{2})/)
        .filter((entry) => entry.includes(`WhatsApp (fake) to +88${phone}`));
      expect(sent.at(-1)).toContain(`ভবঘুরে হলিডেজ · Bhabaghure Holidays\n`);
      expect(sent.at(-1)).toContain(reference);
    } finally {
      await saveContact('');
    }
  });

  test('saving a package in the CMS refreshes the website', async ({ page, request }) => {
    // Nepal 04: the other website tests use the Mustang package, so this one is renamed and then restored.
    const slug = 'kathmandu-nagarkot-himalayan-tour-3-nights-4-days-without-air-ticket';
    await page.goto(`/en/packages/${slug}`);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Kathmandu & Nagarkot');

    // Sign in as staff through the API, as the admin app does, and rename the package.
    const password = 'e2e-website-editor-pass';
    artisan('tinker', `--execute=App\\Models\\Staff::query()->updateOrCreate(['email' => 'web.editor@e2e.test'], ['employee_code' => 'E2E-WEB', 'name' => 'Web editor', 'password' => '${password}', 'status' => 'active', 'must_change_password' => false])->syncRoles(['tour_operator']);`);
    const login = await request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { data: { email: 'web.editor@e2e.test', password } });
    const token = (await login.json()).access_token as string;
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

    const list = await (await request.get(`${E2E_API_URL}/api/v1/admin/packages?search=Nepal%2004`, { headers })).json();
    const id = list.data[0].id as number;
    const pkg = (await (await request.get(`${E2E_API_URL}/api/v1/admin/packages/${id}`, { headers })).json()).data;
    const save = (titleEn: string) => request.put(`${E2E_API_URL}/api/v1/admin/packages/${id}`, { headers, data: { ...pkg, title_en: titleEn } });

    try {
      expect((await save('Kathmandu & Nagarkot (renamed in the CMS)')).status()).toBe(200);

      // The API called /api/revalidate on save; the next visit renders the new title.
      await expect.poll(async () => {
        await page.goto(`/en/packages/${slug}`);
        return page.getByRole('heading', { level: 1 }).innerText();
      }, { timeout: 20_000 }).toContain('renamed in the CMS');
    } finally {
      await save(pkg.title_en);
    }
  });
});
