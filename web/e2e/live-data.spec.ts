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

  test('a visa service published in the CMS appears in the Visa section, on its own page and in the Visa tab', async ({ page, request }) => {
    const password = 'e2e-visa-editor-pass';
    artisan('tinker', `--execute=App\\Models\\Staff::query()->updateOrCreate(['email' => 'visa.editor@e2e.test'], ['employee_code' => 'E2E-VISA', 'name' => 'Visa editor', 'password' => '${password}', 'status' => 'active', 'must_change_password' => false])->syncRoles(['admin']);`);
    const login = await request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { data: { email: 'visa.editor@e2e.test', password } });
    const headers = { Authorization: `Bearer ${(await login.json()).access_token as string}`, Accept: 'application/json' };

    // Nothing published: no section, no tab, no menu link.
    await page.goto('/en');
    await expect(page.locator('#visa')).toHaveCount(0);
    await expect(page.locator('#search').getByRole('tab', { name: 'Visa', exact: true })).toHaveCount(0);

    const created = await request.post(`${E2E_API_URL}/api/v1/admin/visas`, {
      headers,
      data: {
        country_code: 'TH', country_bn: 'থাইল্যান্ড', country_en: 'Thailand', visa_type_bn: 'টুরিস্ট ভিসা', visa_type_en: 'Tourist visa', price: 5500,
        processing_bn: '৭–১০ কর্মদিবস', processing_en: '7–10 working days', stay_en: 'Single entry, up to 60 days',
        requirements_bn: 'ছয় মাস মেয়াদি পাসপোর্ট\nদুই কপি ছবি', requirements_en: 'Passport valid for 6 months\nTwo photos, 35 × 45 mm\nFor business person:\nRenewed trade license',
        notes_en: 'Apply at least 3 weeks before travel.',
      },
    });
    expect(created.status()).toBe(201);
    const id = (await created.json()).data.id as number;
    try {
      expect((await request.post(`${E2E_API_URL}/api/v1/admin/visas/${id}/publish`, { headers })).status()).toBe(200);

      // The publish called /api/revalidate: the next visit shows the section.
      await expect.poll(async () => {
        await page.goto('/en');
        return page.locator('#visa').count();
      }, { timeout: 20_000 }).toBe(1);
      const card = page.getByTestId('visa-countries').locator('article').filter({ hasText: 'Thailand' });
      await expect(card).toContainText('Tourist visa');
      await expect(card).toContainText('৳ 5,500per person');
      await expect(card).toContainText('Processing: 7–10 working days');

      // The Visa tab lists the country's visas.
      await page.locator('#search').getByRole('tab', { name: 'Visa', exact: true }).click();
      await expect(page.getByTestId('visa-finder-results')).toContainText('Tourist visa');

      // Its page: price, processing, stay, the requirements as a list, the note; Bangla at the unprefixed address.
      await card.getByRole('link', { name: 'Requirements & details →' }).click();
      await expect(page).toHaveURL(/\/en\/visa\/thailand-tourist-visa$/);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Thailand Tourist visa');
      // A line ending in a colon heads its own group, numbered from one, rather than being a requirement itself.
      await expect(page.getByTestId('visa-requirements').locator('li')).toHaveText(['Passport valid for 6 months', 'Two photos, 35 × 45 mm', 'Renewed trade license']);
      await expect(page.getByTestId('visa-requirements').getByRole('heading', { level: 3 })).toHaveText(['For business person']);
      await expect(page.getByTestId('visa-requirements').locator('ol')).toHaveCount(2);
      await expect(page.locator('main')).toContainText('Single entry, up to 60 days');
      await expect(page.locator('main')).toContainText('Apply at least 3 weeks before travel.');
      await page.goto('/visa/thailand-tourist-visa');
      await expect(page.getByTestId('visa-requirements').locator('li')).toHaveText(['ছয় মাস মেয়াদি পাসপোর্ট', 'দুই কপি ছবি']);
      await expect(page.locator('main')).toContainText('৳ ৫,৫০০ জনপ্রতি');
      // The requirements PDF is for signed-in customers: a visitor is asked to sign in first (Phase 8 §4.E).
      await page.getByTestId('download-visa').click();
      await expect(page.getByRole('dialog', { name: 'ডাউনলোড করতে সাইন ইন করুন' })).toBeVisible();
      expect((await request.get('/sitemap.xml')).status()).toBe(200);
    } finally {
      await request.delete(`${E2E_API_URL}/api/v1/admin/visas/${id}`, { headers });
    }
  });

  test('a package priced by hotel category: the card shows basic/3-star, the modal picks the category, and the booking is charged from its row', async ({ page, request }) => {
    const thai = 'thailand-budget-escape-bangkok-pattaya-coral-island-with';
    const refresh = () => request.post('/api/revalidate', { headers: { Authorization: 'Bearer e2e-revalidate-secret' }, data: { tags: ['packages'] } });
    const grid = JSON.stringify({ 3: { 1: 35000, 2: 27500, 4: 25000, 6: 24000, 10: 22000 }, 4: { 1: 45000, 2: 36000, 4: 33000 } });
    artisan('tinker', `--execute=App\\Models\\TourPackage::query()->where('slug', '${thai}')->update(['price_grid' => '${grid}', 'regular_price' => 27500, 'sale_price' => null]); echo 'ok';`);
    try {
      expect((await refresh()).status()).toBe(200);
      const card = page.locator('#packages article').filter({ hasText: 'THAILAND BUDGET ESCAPE' });
      await expect.poll(async () => {
        await page.goto('/en');
        return card.innerText();
      }, { timeout: 20_000 }).toContain('per person · Basic / 3-star · 2 travellers');
      await expect(card.locator('.text-price')).toHaveText('৳ 27,500');

      // The modal: the category first, then the group size; 4 travellers in 4-star pay the 4-traveller price.
      await card.getByRole('link', { name: /THAILAND BUDGET ESCAPE/ }).click();
      const detail = page.getByRole('dialog', { name: /THAILAND BUDGET ESCAPE/ });
      const categories = detail.getByTestId('hotel-categories');
      await expect(categories.getByRole('radio', { name: 'Basic / 3-star' })).toHaveAttribute('aria-checked', 'true');
      await expect(categories.getByRole('radio')).toHaveText(['Basic / 3-star', '4-star']);
      await categories.getByRole('radio', { name: '4-star' }).click();
      await detail.getByRole('button', { name: /^4 people/ }).click();
      await expect(detail.getByText('Group total').locator('..')).toContainText('৳ 1,32,000');
      await expect(detail.getByRole('button', { name: /^2 people/ })).toContainText('৳ 36,000');
      await expect(detail).toContainText('4-star hotel · a single room adds 12% for two or more');

      // Booking starts in 4-star: 33,000 × 4 = 1,32,000 + 2% = 1,34,640, and the API charges exactly that.
      await detail.getByRole('button', { name: 'Book now' }).click();
      const dialog = page.getByRole('dialog', { name: 'Book online' });
      await expect(dialog.getByLabel('Hotel category')).toHaveValue('4');
      await dialog.getByLabel('Departure date').fill(new Date(Date.now() + 62 * 86_400_000).toISOString().slice(0, 10));
      await dialog.getByRole('button', { name: 'Next step →' }).click();
      const lead = dialog.locator('section').nth(0);
      await lead.getByLabel('Name (as on passport)').fill('KARIM HOSSAIN');
      await lead.getByLabel('WhatsApp number').fill(uniquePhone());
      await dialog.getByRole('button', { name: 'Next step →' }).click();
      await expect(dialog).toContainText('THAILAND BUDGET ESCAPE — Bangkok · Pattaya · Coral Island with Dinner Cruise · 4-star × 4');
      await expect(dialog.getByTestId('booking-total')).toHaveText('৳ 1,34,640');
      await dialog.getByRole('checkbox').check();
      await dialog.getByRole('button', { name: 'Next step →' }).click();
      await dialog.getByRole('button', { name: 'Pay ৳ 1,34,640 with SSLCommerz →' }).click();
      await expect(page.locator('body')).toContainText('BDT 134,640.00');
    } finally {
      artisan('tinker', `--execute=App\\Models\\TourPackage::query()->where('slug', '${thai}')->update(['price_grid' => null, 'regular_price' => 30000, 'sale_price' => 27000]); echo 'ok';`);
      await refresh();
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

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.F: the payment details staff enter in Site settings reach the
 * booking's own page with that booking's amounts. The e2e API's checkout takes payments, so the hosted payment link
 * stays hidden and the bank and bKash details sit under the checkout.
 */
test.describe('how to pay', () => {
  test('the booking page shows the bank account and the bKash amount with its charge', async ({ page, request }) => {
    const password = 'e2e-website-payment-pass';
    artisan('tinker', `--execute=App\\Models\\Staff::query()->updateOrCreate(['email' => 'web.payment@e2e.test'], ['employee_code' => 'E2E-PAY', 'name' => 'Payment editor', 'password' => '${password}', 'status' => 'active', 'must_change_password' => false])->syncRoles(['admin']);`);
    const login = await request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { data: { email: 'web.payment@e2e.test', password } });
    const headers = { Authorization: `Bearer ${(await login.json()).access_token as string}`, Accept: 'application/json' };
    const save = (value: unknown) => request.put(`${E2E_API_URL}/api/v1/admin/settings/payment`, { headers, data: { value } });

    try {
      expect(
        (
          await save({
            bank: { bankName: 'Example Trust Bank', accountName: 'Example Holidays', accountNumber: '1310000000001', branch: 'Mirpur', routingNumber: '145260001', transferType: 'NPSB' },
            link: 'https://invoice.sslcommerz.com/invoice-form?refer=EXAMPLE',
            bkash: { number: '+8801613000000', chargePercent: 1.3 },
          })
        ).status(),
      ).toBe(200);

      const phone = uniquePhone();
      const created = await request.post(`${E2E_API_URL}/api/v1/public/bookings`, {
        headers: { Accept: 'application/json' },
        data: {
          package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights',
          travel_date: new Date(Date.now() + 50 * 86_400_000).toISOString().slice(0, 10),
          pax: 1, room: 'twin', addons: [], travellers: [{ name: 'PAYMENT READER', phone }],
          expected_total: 76500, terms_accepted: true, locale: 'en',
        },
      });
      expect(created.status()).toBe(201);
      const booking = (await created.json()).data as { reference: string; accessToken: string };

      await page.goto(`/en/booking/${booking.reference}#t=${booking.accessToken}`);
      const how = page.getByTestId('payment-instructions');
      await expect(how.getByRole('heading', { name: 'Or pay by hand' })).toBeVisible();
      await expect(how).toContainText(`Write your booking reference ${booking.reference} with the payment`);
      await expect(how.getByTestId('pay-bank')).toContainText('1310000000001');
      await expect(how.getByTestId('pay-bank')).toContainText('145260001');
      // ৳ 76,500 due; bKash adds 1.3% (৳ 995), so ৳ 77,495 to send. The link is hidden while the checkout works.
      await expect(how.getByTestId('pay-bkash')).toContainText('01613000000');
      await expect(how.getByTestId('pay-bkash')).toContainText('৳ 77,495');
      await expect(how.getByTestId('pay-link')).toHaveCount(0);
    } finally {
      await save({ bank: null, link: null, bkash: null });
    }
  });
});
