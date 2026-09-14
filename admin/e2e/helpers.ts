import { expect, type Page } from '@playwright/test'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { API_DIR, E2E_API_URL } from '../../scripts/e2e-api.mjs'

export { E2E_API_URL as API_URL } from '../../scripts/e2e-api.mjs'
export const PHOTO = resolve(import.meta.dirname, '.state/photo.jpg')

export const password = () => (JSON.parse(readFileSync(resolve(import.meta.dirname, '.state/staff.json'), 'utf8')) as { password: string }).password

/**
 * The first data on a screen after a full page load. The e2e API is PHP's built-in server, one request at a time: a
 * reload queues refresh, me, nav-counts and each list the screen loads (Customers: board and list; Payments: five
 * cards), which can pass 5 s here. Production serves them in parallel. Later waits on the same screen keep the default.
 */
export const FIRST_LOAD = { timeout: 15_000 }

/** Signs in through the real login form, in English so assertions read naturally. */
export async function signIn(page: Page, role: 'super_admin' | 'admin' | 'tour_operator' | 'sales_agent' | 'new.hire') {
  await page.goto('/login')
  await page.evaluate(() => localStorage.setItem('bh-lang', 'en'))
  await page.reload()
  await page.getByLabel('Email').fill(`${role}@e2e.test`)
  await page.getByLabel('Password').fill(password())
  await page.getByRole('button', { name: 'Sign in' }).click()
  // Login, refresh and "me" queue on the e2e API's single-threaded PHP server; under a full run that can pass 5 s.
  await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 })
}

/** The staff API as one role, for arranging data a test isn't about (signs in through the API, not the form). */
export async function staffApi(page: Page, role: 'admin' | 'sales_agent') {
  const login = await page.request.post(`${E2E_API_URL}/api/v1/staff/auth/login`, { headers: { Accept: 'application/json' }, data: { email: `${role}@e2e.test`, password: password() } })
  expect(login.status()).toBe(200)
  const headers = { Accept: 'application/json', Authorization: `Bearer ${((await login.json()) as { access_token: string }).access_token}` }

  return {
    post: async <T>(path: string, data?: unknown): Promise<T> => {
      const response = await page.request.post(`${E2E_API_URL}/api/v1/${path}`, { headers, data })
      expect(response.status(), `${path}: ${await response.text()}`).toBeLessThan(300)
      return (await response.json()) as T
    },
    /** Multipart, with the e2e photo as the receipt a money movement carries. */
    postWithReceipt: async <T>(path: string, fields: Record<string, string | number>, fileField = 'evidence'): Promise<T> => {
      const response = await page.request.post(`${E2E_API_URL}/api/v1/${path}`, {
        headers,
        multipart: { ...Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, String(value)])), [fileField]: { name: 'receipt.jpg', mimeType: 'image/jpeg', buffer: readFileSync(PHOTO) } },
      })
      expect(response.status(), `${path}: ${await response.text()}`).toBeLessThan(300)
      return (await response.json()) as T
    },
  }
}

/** A lead and a quotation for them at the Mustang price (2 travellers, ৳ 1,53,000), sent unless asked not to. */
export async function quotationFor(page: Page, name: string, { send = true, role = 'admin' as 'admin' | 'sales_agent' } = {}): Promise<{ id: number; number: string; customerId: number }> {
  const api = await staffApi(page, role)
  const phone = `0171${String(Date.now()).slice(-7)}`
  const customer = await api.post<{ data: { id: number } }>('admin/customers', { name, phone, email: null, source: 'walk_in', interest: null })
  const created = await api.post<{ data: { id: number; number: string } }>('admin/quotations', {
    customer_id: customer.data.id,
    package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights',
    travel_date: new Date(Date.now() + 60 * 86_400_000).toISOString().slice(0, 10),
    pax: 2,
    room: 'twin',
    addons: [],
    discount: 0,
    vat_rate: 2,
    validity_days: 7,
    locale: 'en',
    notes: null,
    expected_total: 153000,
  })
  if (send) await api.post(`admin/quotations/${created.data.id}/send`)
  return { id: created.data.id, number: created.data.number, customerId: customer.data.id }
}

/** A booking made the way the website makes one (public API), 70 days out, two travellers. */
export async function websiteBooking(page: Page, name: string, email?: string): Promise<{ reference: string; phone: string }> {
  const travelDate = new Date(Date.now() + 70 * 86_400_000).toISOString().slice(0, 10)
  const phone = `0171${String(Date.now()).slice(-7)}`
  const response = await page.request.post(`${E2E_API_URL}/api/v1/public/bookings`, {
    headers: { Accept: 'application/json' },
    data: {
      package_slug: 'nepal-mustang-adventure-tour-8-days-7-nights',
      travel_date: travelDate,
      pax: 2,
      room: 'twin',
      addons: [],
      travellers: [
        { name, passport_number: 'BW0912345', date_of_birth: '1990-04-12', passport_expiry: '2031-03-12', phone, ...(email ? { email } : {}) },
        { name: 'Nusrat Jahan', passport_number: 'BX4471228', date_of_birth: '1994-11-02', passport_expiry: '2029-07-11' },
      ],
      expected_total: 153000,
      terms_accepted: true,
      locale: 'en',
    },
  })
  expect(response.status()).toBe(201)
  return { reference: ((await response.json()) as { data: { reference: string } }).data.reference, phone }
}

/** The last message the fake WhatsApp gateway "sent" to a number (+8801…), from api/storage/logs/whatsapp-fake.log. */
export function lastFakeWhatsApp(to: string): string {
  const file = resolve(API_DIR, 'storage/logs/whatsapp-fake.log')
  if (!existsSync(file)) return ''
  const log = readFileSync(file, 'utf8')
  return (
    log
      .split(/\r?\n(?=\[\d{4}-\d{2}-\d{2})/)
      .filter((entry) => entry.includes(`WhatsApp (fake) to ${to}`))
      .at(-1) ?? ''
  )
}
