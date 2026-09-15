import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { FIRST_LOAD, signIn } from './helpers'

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.E: the Downloads screen shows the Super Admin who downloaded which
 * brochure or visa requirements, with their choice, and who has nothing in progress yet; the customer's profile lists them.
 */
test('the super admin sees who downloaded what, filters the ones to follow up, and the profile lists their downloads', async ({ page }) => {
  const phone = `88017${String(Date.now()).slice(-8)}`
  const name = `Brochure Lead ${phone.slice(-4)}`
  artisan(
    'tinker',
    `--execute=$c = App\\Models\\Customer::query()->forceCreate(['name' => '${name}', 'phone' => '${phone}', 'stage' => 'lead', 'source' => 'website_form']); ` +
      `$p = App\\Models\\TourPackage::query()->where('slug', 'nepal-mustang-adventure-tour-8-days-7-nights')->sole(); ` +
      `App\\Models\\Download::query()->forceCreate(['customer_id' => $c->id, 'kind' => 'package', 'tour_package_id' => $p->id, 'title' => $p->title_en, 'hotel_category' => '4', 'pax' => 2, 'locale' => 'en', 'created_at' => now()->subHours(3)]); ` +
      `App\\Models\\Download::query()->forceCreate(['customer_id' => $c->id, 'kind' => 'visa', 'title' => 'Thailand Tourist visa', 'locale' => 'bn', 'created_at' => now()->subHour()]); echo 'ok';`,
  )

  // The Super Admin's unless the Roles screen grants it (row-actions.spec grants it to the e2e admin).
  await signIn(page, 'sales_agent')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('link', { name: /Customers & leads/ })).toBeVisible(FIRST_LOAD)
  await expect(nav.getByRole('link', { name: '↓ Downloads' })).toHaveCount(0)
  await page.goto('/downloads')
  await expect(page.getByText("You don't have access to this screen")).toBeVisible()

  await signIn(page, 'super_admin')
  await nav.getByRole('link', { name: '↓ Downloads' }).click()
  await expect(page.getByRole('heading', { name: 'Downloads', level: 1 })).toBeVisible()
  await expect(page.getByTestId('download-totals')).toContainText('Package brochures')

  await page.getByRole('searchbox', { name: 'Search by name, mobile or package' }).fill(phone.slice(2))
  const rows = page.getByTestId('downloads-table').locator('tbody tr')
  await expect(rows).toHaveCount(2)
  // Newest first: the visa, then the brochure with the hotel category and travellers chosen.
  await expect(rows.nth(0)).toContainText('Thailand Tourist visa')
  await expect(rows.nth(1).getByTestId('download-choice')).toHaveText('4-star · 2 travellers')
  await expect(rows.nth(1)).toContainText(name)
  await expect(rows.nth(1)).toContainText('Lead · Pool · 2 downloads')
  await expect(rows.nth(1)).toContainText('Nothing in progress')
  await expect(rows.nth(1).getByRole('link', { name: `WhatsApp — ${name}` })).toHaveAttribute('href', new RegExp(`^https://wa\\.me/${phone}\\?text=`))

  // "Nothing in progress" keeps them; the kind chips narrow to visas.
  await page.getByRole('radio', { name: 'Nothing in progress' }).click()
  await expect(rows).toHaveCount(2)
  await page.getByRole('radio', { name: 'Visa', exact: true }).click()
  await expect(rows).toHaveCount(1)

  // The customer's profile lists both, and links back to the filtered list.
  await rows.nth(0).getByRole('link', { name, exact: true }).click()
  const card = page.getByTestId('customer-downloads')
  await expect(card.locator('li')).toHaveCount(2)
  await expect(card.locator('li').nth(1)).toContainText('4-star · 2 travellers')
  await page.getByRole('link', { name: 'All downloads' }).click()
  await expect(page).toHaveURL(new RegExp(`/downloads\\?search=${phone}$`))
  await expect(rows).toHaveCount(2)
})
