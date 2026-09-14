import { expect, type Page } from '@playwright/test'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { API_DIR, E2E_API_URL } from '../../scripts/e2e-api.mjs'

export { E2E_API_URL as API_URL } from '../../scripts/e2e-api.mjs'
export const PHOTO = resolve(import.meta.dirname, '.state/photo.jpg')

export const password = () => (JSON.parse(readFileSync(resolve(import.meta.dirname, '.state/staff.json'), 'utf8')) as { password: string }).password

/** Signs in through the real login form, in English so assertions read naturally. */
export async function signIn(page: Page, role: 'super_admin' | 'admin' | 'tour_operator' | 'sales_agent' | 'new.hire') {
  await page.goto('/login')
  await page.evaluate(() => localStorage.setItem('bh-lang', 'en'))
  await page.reload()
  await page.getByLabel('Email').fill(`${role}@e2e.test`)
  await page.getByLabel('Password').fill(password())
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).not.toHaveURL(/\/login$/)
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
