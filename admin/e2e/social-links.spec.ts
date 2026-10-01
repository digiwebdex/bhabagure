import { expect, test } from '@playwright/test'

import { API_URL, FIRST_LOAD, signIn, staffApi } from './helpers'

/**
 * Site settings → Contact: the company's social links, shown as icons in the website's footer (client, 2026-10-02). A
 * link is a full https address; one left empty shows no icon.
 */

type Contact = Record<string, string | null | undefined>

test('the footer’s social links are filled in Site settings → Contact and served to the website', async ({ page }) => {
  const api = await staffApi(page, 'admin')
  const before = (await api.get<{ data: { contact: Contact } }>('admin/settings')).data.contact
  const publicContact = async () => ((await (await page.request.get(`${API_URL}/api/v1/public/settings`)).json()) as { data: { contact: Contact } }).data.contact

  try {
    // As on the live site: contact details saved before the TikTok, LinkedIn and YouTube links existed.
    const saved = Object.fromEntries(Object.entries(before).filter(([field]) => !['tiktok', 'linkedin', 'youtube'].includes(field)))
    await api.put('admin/settings/contact', { value: saved })

    await signIn(page, 'admin')
    await page.goto('/settings')
    const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Contact', exact: true }) })
    await expect(card.getByText('Social links show as icons in the website’s footer')).toBeVisible(FIRST_LOAD)
    const tiktok = card.getByLabel('TikTok', { exact: true })

    // Not a full https address: the field says so and nothing is saved.
    await tiktok.fill('tiktok.com/@bhabaghure')
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(tiktok).toHaveAttribute('aria-invalid', 'true')
    expect((await publicContact()).tiktok).toBeUndefined()

    await tiktok.fill('https://www.tiktok.com/@bhabaghure')
    await card.getByLabel('LinkedIn', { exact: true }).fill('https://www.linkedin.com/company/bhabaghure')
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    // Saved, though the API sends the value back with its fields in another order.
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
    expect(await publicContact()).toMatchObject({ facebook: before.facebook, instagram: before.instagram, tiktok: 'https://www.tiktok.com/@bhabaghure', linkedin: 'https://www.linkedin.com/company/bhabaghure' })
    expect((await publicContact()).youtube ?? null).toBeNull()

    // Emptied again: saved as no link, so its icon goes.
    await tiktok.fill('')
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(card.getByRole('button', { name: 'Saved', exact: true })).toBeVisible()
    expect((await publicContact()).tiktok).toBeNull()
  } finally {
    await api.put('admin/settings/contact', { value: before })
  }
})
