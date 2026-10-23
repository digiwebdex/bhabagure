import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'

import { FIRST_LOAD, signIn } from './helpers'

/**
 * docs/visitor-analytics.md: Website → Visitor analytics over known visits — the figures, the map, a country filter,
 * live visitors, the CSV, and the settings the super admin alone may change.
 */
test.describe.configure({ mode: 'serial' })

test.beforeAll(() => {
  // Today: two visits from Dhaka (one still on the website, on a phone, from Google, that sent an enquiry), one from
  // London, one with no known country.
  const php = `
    use App\\Models\\AnalyticsSession; use App\\Models\\AnalyticsEvent; use Illuminate\\Support\\Str;
    AnalyticsEvent::query()->delete(); AnalyticsSession::query()->delete();
    $make = function (array $a, array $paths, ?string $conversion = null) {
      $s = AnalyticsSession::query()->create($a + ['token' => (string) Str::uuid(), 'visitor_hash' => hash('sha256', Str::random()), 'landing_path' => $paths[0], 'last_path' => end($paths), 'page_views' => count($paths), 'browser' => 'Chrome', 'os' => 'Android', 'locale' => 'en']);
      foreach ($paths as $p) { AnalyticsEvent::query()->create(['session_id' => $s->id, 'event_key' => Str::random(40), 'type' => 'page_view', 'path' => $p, 'subject' => App\\Services\\Analytics\\Paths::subject($p), 'occurred_at' => $a['started_at']]); }
      if ($conversion) { AnalyticsEvent::query()->create(['session_id' => $s->id, 'event_key' => Str::random(40), 'type' => $conversion, 'path' => end($paths), 'occurred_at' => $a['started_at']]); }
    };
    $make(['started_at' => now()->subMinutes(20), 'last_seen_at' => now(), 'country_code' => 'BD', 'region' => 'Dhaka Division', 'city' => 'Dhaka', 'device' => 'mobile', 'source' => 'search', 'referrer_domain' => 'google.com', 'engaged_seconds' => 240], ['/', '/packages/nepal-mustang'], 'enquiry');
    $make(['started_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(3), 'country_code' => 'BD', 'city' => 'Chattogram', 'device' => 'desktop', 'source' => 'direct', 'engaged_seconds' => 60], ['/']);
    $make(['started_at' => now()->subHours(5), 'last_seen_at' => now()->subHours(5), 'country_code' => 'GB', 'city' => 'London', 'device' => 'desktop', 'source' => 'social', 'referrer_domain' => 'facebook.com', 'engaged_seconds' => 30], ['/visa/thailand']);
    $make(['started_at' => now()->subHours(6), 'last_seen_at' => now()->subHours(6), 'device' => 'tablet', 'source' => 'direct', 'engaged_seconds' => 0], ['/']);
    echo 'ok';`
  artisan('tinker', `--execute=${php.replace(/\n\s*/g, ' ')}`)
})

test('the overview, the map and a country filter show the recorded visits', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/analytics')
  await expect(page.getByRole('heading', { name: 'Visitor analytics' })).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('kpi-sessions')).toContainText('4', FIRST_LOAD)
  await expect(page.getByTestId('kpi-pageviews')).toContainText('5')
  await expect(page.getByTestId('kpi-countries')).toContainText('2')
  await expect(page.getByTestId('kpi-countries')).toContainText('Known for 75% of sessions')
  await expect(page.getByTestId('kpi-conversions')).toContainText('1')
  await expect(page.getByTestId('kpi-active')).toContainText('1')
  await expect(page.getByTestId('analytics-trend')).toBeVisible()
  // The settings are the super admin's.
  await expect(page.getByRole('radio', { name: 'Settings' })).toHaveCount(0)

  await page.getByRole('radio', { name: 'Locations' }).click()
  await expect(page.getByTestId('analytics-map').locator('path[data-country="BD"]')).toHaveAttribute('data-sessions', '2', FIRST_LOAD)
  await expect(page.getByTestId('analytics-map').locator('path[data-country="GB"]')).toHaveAttribute('data-sessions', '1')
  const countries = page.getByTestId('analytics-countries')
  await expect(countries.locator('tbody tr')).toHaveCount(3)
  await expect(countries.locator('tbody tr').nth(0)).toContainText('Bangladesh')
  await expect(countries.locator('tbody tr').nth(2)).toContainText('Unknown')
  await expect(page.getByTestId('analytics-cities')).toContainText('Dhaka, Dhaka Division, Bangladesh')

  // Bangladesh only: every report follows, and the filter is in the address.
  await countries.getByRole('button', { name: /Bangladesh/ }).click()
  await expect(page.getByTestId('analytics-filters')).toContainText('Country: BD')
  await expect(page).toHaveURL(/country=BD/)
  await expect(page.getByTestId('analytics-cities').locator('tbody tr')).toHaveCount(2)
  await page.getByRole('radio', { name: 'Overview' }).click()
  await expect(page.getByTestId('kpi-sessions')).toContainText('2', FIRST_LOAD)
  await page.getByRole('button', { name: 'Remove the filter BD' }).click()
  await expect(page.getByTestId('kpi-sessions')).toContainText('4', FIRST_LOAD)

  // Pages and tours; sources; conversions.
  await page.getByRole('radio', { name: 'Pages & tours' }).click()
  await expect(page.getByTestId('analytics-pages').locator('tbody tr').first()).toContainText('/')
  await expect(page.getByTestId('analytics-tours')).toContainText('package:nepal-mustang')
  await page.getByRole('radio', { name: 'Traffic sources' }).click()
  await expect(page.getByTestId('analytics-referrers')).toContainText('facebook.com')
  await page.getByRole('radio', { name: 'Conversions' }).click()
  await expect(page.getByTestId('analytics-conversions')).toContainText('enquiry')

  // A CSV of the countries, with the screen's period.
  await page.getByRole('radio', { name: 'Locations' }).click()
  const download = page.waitForEvent('download')
  await page.locator('section').filter({ has: page.getByRole('heading', { name: 'Countries' }) }).getByRole('button', { name: 'CSV' }).click()
  expect((await download).suggestedFilename()).toMatch(/^bhabaghure-analytics-country-\d{4}-\d{2}-\d{2}-\d{4}-\d{2}-\d{2}\.csv$/)
})

test('live lists only the visit seen in the last minutes, and the super admin changes the settings', async ({ page }) => {
  await signIn(page, 'super_admin')
  await page.goto('/analytics?tab=live')
  const live = page.getByTestId('analytics-live')
  await expect(live.locator('li')).toHaveCount(1, FIRST_LOAD)
  await expect(live).toContainText('Dhaka, Bangladesh · Phone')
  await expect(live).toContainText('Now on /packages/nepal-mustang · came in on /')
  await expect(page.getByTestId('analytics-recent')).toContainText('Enquiry sent')

  await page.getByRole('radio', { name: 'Settings' }).click()
  await page.getByLabel('Keep visits for (months)').fill('40')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('The retention months field must be between 1 and 36.')).toBeVisible()
  await page.getByLabel('Keep visits for (months)').fill('13')
  await page.getByLabel('Pages never counted').fill('/blog')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Saved', { exact: true }).first()).toBeVisible()
  await page.reload()
  await expect(page.getByLabel('Pages never counted')).toHaveValue('/blog', FIRST_LOAD)
  // Back as it was.
  await page.getByLabel('Pages never counted').fill('')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Saved', { exact: true }).first()).toBeVisible()
})
