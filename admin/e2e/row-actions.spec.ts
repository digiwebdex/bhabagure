import { expect, test, type Page } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { quotationFor, signIn, staffApi, websiteBooking } from './helpers'

/**
 * docs/phase-5-admin-core.md §3.2: the sticky row-actions cell, measured on the real admin tables — the eight failure
 * modes F1–F8 with icons (at a width where each table overflows) and at 390 px ("⋯"), light and dark, at rest /
 * hovered / selected, scrolled 0, 50 and 100 %. Checks use computed styles, bounding boxes, elementFromPoint hit-tests
 * and pixels sampled from screenshots.
 */
test.describe.configure({ mode: 'serial' })

type Theme = 'light' | 'dark'

/** Every admin table with row actions, at a width where its columns overflow the card. Quotations add → Convert (§2 #10). */
const TABLES = [
  { name: 'Bookings', url: '/bookings', testId: 'bookings-table', width: 1024, icons: 7 },
  { name: 'Customers', url: '/customers?stage=lead', testId: 'customers-table', width: 700, icons: 7 },
  { name: 'Quotations', url: '/quotations', testId: 'quotations-table', width: 1024, icons: 8 },
  { name: 'Cash book', url: '/payments', testId: 'cash-book-table', width: 1024, icons: 7 },
  // WhatsApp and email only, and Mark as quoted (§4.7).
  { name: 'Air ticketing', url: '/air-ticketing?state=all', testId: 'air-inquiries-table', width: 700, icons: 4 },
  // Open file, Verify, Reject, Open booking (docs/phase-6-customer-portal.md §3.3).
  { name: 'Documents', url: '/documents?status=uploaded', testId: 'document-reviews-table', width: 700, icons: 4 },
  // Open ticket, WhatsApp, Open customer (§3.5).
  { name: 'Support', url: '/support?status=all', testId: 'support-tickets-table', width: 700, icons: 3 },
  // Open record, WhatsApp, email (docs/phase-7-hr-attendance-bonus-wallet.md §4.1).
  { name: 'Staff', url: '/staff', testId: 'staff-table', width: 700, icons: 3 },
  // Open file, Replace, Archive, Open staff record (§4.2).
  { name: 'Vault', url: '/vault', testId: 'staff-documents-table', width: 700, icons: 4 },
  // Open days (§5.1).
  { name: 'Attendance', url: '/attendance', testId: 'attendance-month-table', width: 700, icons: 1 },
  // Approve, Reject, Revoke, Open their days (§5.1).
  { name: 'Leave requests', url: '/attendance?status=all', testId: 'leave-requests-table', width: 700, icons: 4 },
] as const

/** The table the helpers below measure. */
let current: (typeof TABLES)[number] = TABLES[0]

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  const bookings = [
    await websiteBooking(page, 'Sticky Cell One', 'sticky.one@example.test'),
    await websiteBooking(page, 'Sticky Cell Two With A Much Longer Customer Name'),
    await websiteBooking(page, 'Sticky Cell Three', 'sticky.three@example.test'),
  ]
  // Portal uploads waiting for review, and support tickets, on those bookings.
  artisan(
    'tinker',
    `--execute=foreach (${JSON.stringify(bookings.map((b) => b.reference))} as $i => $ref) { $b = App\\Models\\Booking::query()->with('travellers')->where('reference', $ref)->firstOrFail(); App\\Models\\TravellerDocument::query()->create(['booking_traveller_id' => $b->travellers->first()->id, 'kind' => 'photo', 'status' => 'uploaded', 'disk' => 'local', 'path' => 'traveller-documents/sticky.enc', 'mime' => 'image/jpeg', 'bytes' => 1, 'source' => 'portal', 'uploaded_at' => now()]); app(App\\Services\\Support\\SupportDesk::class)->open($b->customer, $b, $i === 1 ? 'Sticky request with a much longer subject about changing the room type' : 'Sticky request '.$i, 'Please help.'); } echo 'ok';`,
  )
  await quotationFor(page, 'Sticky Quote One')
  await quotationFor(page, 'Sticky Quote Two With A Much Longer Customer Name', { send: false })
  await quotationFor(page, 'Sticky Quote Three')
  const admin = await staffApi(page, 'admin')
  for (const [direction, category, description] of [['out', 'office_rent', 'Sticky office rent'], ['in', 'other_income', 'Sticky commission from an airline with a long description'], ['out', 'marketing', 'Sticky Facebook ads']]) {
    await admin.postWithReceipt('admin/cash-entries', { direction, amount: 1200, method: 'cash', category, description })
  }
  // Staff documents for the Vault table, one with a long title.
  artisan(
    'tinker',
    `--execute=foreach (App\\Models\\Staff::query()->orderBy('id')->take(3)->get() as $i => $s) { App\\Models\\StaffDocument::query()->create(['staff_id' => $s->id, 'type' => $i === 1 ? 'certificate' : 'passport', 'title' => $i === 1 ? 'IATA fares and ticketing course certificate with a long title' : null, 'number' => 'BX447122'.$i, 'expires_on' => now()->addDays(20 + $i * 40)->toDateString(), 'disk' => 'local', 'path' => 'staff-documents/sticky.enc', 'mime' => 'application/pdf', 'bytes' => 1]); } echo 'ok';`,
  )
  // Leave requests for the queue, one with a long reason.
  artisan(
    'tinker',
    `--execute=foreach (App\\Models\\Staff::query()->orderBy('id')->skip(1)->take(3)->get() as $i => $s) { app(App\\Services\\Attendance\\LeaveDesk::class)->file($s, now()->addDays(60 + $i * 3)->toDateString(), now()->addDays(61 + $i * 3)->toDateString(), $i === 1 ? 'Sticky leave for a family wedding in Sylhet, travelling by the night coach both ways' : 'Sticky leave '.$i, $s); } echo 'ok';`,
  )
  // Air enquiries straight into the table (the public form is rate-limited per address).
  artisan(
    'tinker',
    `--execute=foreach (['Sticky Air One', 'Sticky Air Two With A Much Longer Passenger Name', 'Sticky Air Three'] as $i => $n) { App\\Models\\Inquiry::query()->create(['type' => 'air_quote', 'name' => $n, 'phone' => '88017110006'.$i.'0', 'email' => 'air'.$i.'@example.test', 'pax' => 2, 'locale' => 'en', 'details' => ['from' => 'Dhaka', 'to' => 'Kuala Lumpur', 'departOn' => '2026-12-10', 'returnOn' => '2026-12-20', 'cabinClass' => 'business']]); } echo 'ok';`,
  )
  await page.close()
})

async function openBookings(page: Page, width: number, theme: Theme) {
  await page.setViewportSize({ width, height: 860 })
  await signIn(page, 'admin')
  await page.evaluate((value) => localStorage.setItem('bh-theme', value), theme)
  await page.goto(current.url)
  // The e2e API is PHP's built-in server, one request at a time (no workers on Windows): a screen that loads several
  // lists at once, like Payments, can take over 5 s to show its table here. Production serves them in parallel.
  await expect(page.getByTestId(current.testId).getByRole('row').nth(1)).toBeVisible({ timeout: 15_000 })
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
}

/** Scrolls the table's own scroll container to a fraction of its horizontal range. */
async function scrollTo(page: Page, fraction: number) {
  await page.getByTestId(current.testId).evaluate((scroller, f) => {
    scroller.scrollLeft = (scroller.scrollWidth - scroller.clientWidth) * f
  }, fraction)
  await page.waitForTimeout(80) // the edge flag updates on the scroll event
}

/** Boxes and styles for one body row's actions cell. */
async function measure(page: Page, rowIndex: number) {
  return page.getByTestId(current.testId).evaluate((scroller, index) => {
    const row = scroller.querySelectorAll('tbody tr')[index] as HTMLTableRowElement
    const cell = row.querySelector('td[data-sticky-actions]') as HTMLTableCellElement
    const th = scroller.querySelector('th[data-sticky-actions]') as HTMLTableCellElement
    const table = scroller.querySelector('table') as HTMLTableElement
    const icons = [...cell.querySelectorAll<HTMLElement>('div > a, div > button')].filter((el) => el.offsetParent !== null)
    const box = (el: Element) => {
      const r = el.getBoundingClientRect()
      return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height }
    }
    const cellStyle = getComputedStyle(cell)
    // Hit-test the middle of each icon and points across the cell's left padding strip.
    const cellBox = cell.getBoundingClientRect()
    const hits = icons.map((icon) => {
      const r = icon.getBoundingClientRect()
      const target = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)
      return !!target && (target === icon || icon.contains(target))
    })
    const stripHits = [0.25, 0.5, 0.75].map((f) => {
      const target = document.elementFromPoint(cellBox.left + 2, cellBox.top + cellBox.height * f)
      return !!target && (target === cell || cell.contains(target))
    })
    return {
      scroller: box(scroller),
      scrollWidth: scroller.scrollWidth,
      clientWidth: scroller.clientWidth,
      scrollLeft: scroller.scrollLeft,
      table: box(table),
      row: box(row),
      cell: box(cell),
      th: box(th),
      icons: icons.map(box),
      hits,
      stripHits,
      position: cellStyle.position,
      right: cellStyle.right,
      zIndex: cellStyle.zIndex,
      paddingLeft: parseFloat(cellStyle.paddingLeft),
      paddingRight: parseFloat(cellStyle.paddingRight),
      cellBg: cellStyle.backgroundColor,
      rowBg: getComputedStyle(row).backgroundColor,
      thBg: getComputedStyle(th).backgroundColor,
      shadow: cellStyle.boxShadow,
      thTextRight: (() => {
        const range = document.createRange()
        range.selectNodeContents(th)
        return range.getBoundingClientRect().right
      })(),
    }
  }, rowIndex)
}

/** Pixel colours from a real screenshot, at page coordinates. */
async function pixels(page: Page, points: { x: number; y: number }[]) {
  const png = await page.screenshot()
  return page.evaluate(
    async ({ base64, points: at }) => {
      const image = new Image()
      image.src = `data:image/png;base64,${base64}`
      await image.decode()
      const canvas = document.createElement('canvas')
      canvas.width = image.width
      canvas.height = image.height
      const context = canvas.getContext('2d')!
      context.drawImage(image, 0, 0)
      return at.map(({ x, y }) => [...context.getImageData(Math.round(x), Math.round(y), 1, 1).data.slice(0, 3)])
    },
    { base64: png.toString('base64'), points },
  )
}

const rgb = (css: string) => (css.match(/\d+(\.\d+)?/g) ?? []).map(Number)
const opaque = (css: string) => rgb(css).length === 3 || rgb(css)[3] === 1

for (const table of TABLES) for (const theme of ['light', 'dark'] as const) {
  test(`${table.name}, ${table.width} px, ${theme}: the actions cell stays reachable, opaque and full height at every scroll position and state (F1–F8)`, async ({ page }) => {
    current = table
    await openBookings(page, table.width, theme)
    const initial = await measure(page, 1)
    expect(initial.scrollWidth, 'the table must overflow for this test to mean anything').toBeGreaterThan(initial.clientWidth + 40)

    for (const fraction of [0, 0.5, 1]) {
      await scrollTo(page, fraction)
      for (const state of ['rest', 'hover', 'selected'] as const) {
        const row = page.getByTestId(current.testId).locator('tbody tr').nth(1)
        await page.mouse.move(0, 0)
        await row.evaluate((tr, s) => tr.setAttribute('data-selected', String(s === 'selected')), state)
        // Hit-tests and screenshots only see the viewport: bring the row into view (the lead board sits above the list).
        await row.scrollIntoViewIfNeeded()
        if (state === 'hover') await row.locator('td').first().hover({ position: { x: 4, y: 4 } })
        const m = await measure(page, 1)
        const label = `${theme} scroll ${fraction * 100}% ${state}`

        // F1 sticky at the scroller's right edge, above scrolled content.
        expect(m.position, label).toBe('sticky')
        expect(m.right, label).toBe('0px')
        expect(Number(m.zIndex), label).toBeGreaterThanOrEqual(1)
        expect(Math.abs(m.cell.right - m.scroller.right), `${label}: cell at the right edge`).toBeLessThanOrEqual(1)
        expect(m.hits.every(Boolean), `${label}: every icon is on top where it is drawn`).toBe(true)
        expect(m.stripHits.every(Boolean), `${label}: nothing shows through the cell`).toBe(true)

        // F2 / F4 opaque, and the row's own colour (hover and selected included).
        expect(opaque(m.cellBg), `${label}: opaque cell (${m.cellBg})`).toBe(true)
        expect(m.cellBg, `${label}: cell inherits the row`).toBe(m.rowBg)

        // F3 full row height.
        expect(Math.abs(m.cell.height - m.row.height), `${label}: cell as tall as the row`).toBeLessThanOrEqual(1)
        expect(m.cell.top).toBeCloseTo(m.row.top, 0)

        // F5 the row runs the full scrolled width.
        expect(Math.abs(m.row.width - m.table.width), `${label}: row as wide as the table`).toBeLessThanOrEqual(1)
        expect(m.table.width, label).toBeGreaterThanOrEqual(m.scrollWidth - 1)

        // F6 every icon, none clipped by the cell.
        expect(m.icons, label).toHaveLength(current.icons)
        for (const icon of m.icons) {
          expect(icon.left, `${label}: icon inside the cell`).toBeGreaterThanOrEqual(m.cell.left + m.paddingLeft - 0.5)
          expect(icon.right, `${label}: icon inside the cell`).toBeLessThanOrEqual(m.cell.right - m.paddingRight + 0.5)
          expect(icon.width, label).toBeCloseTo(24, 0)
        }

        // F7 a right gutter between the last icon and the card edge.
        expect(m.scroller.right - m.icons.at(-1)!.right, `${label}: gutter`).toBeGreaterThanOrEqual(16)

        // F8 the edge is marked only while content is hidden underneath; the header label lines up with the icons.
        const hidden = m.scrollLeft + m.clientWidth < m.scrollWidth - 1
        expect(m.shadow !== 'none', `${label}: edge shadow ${hidden ? 'shown' : 'hidden'}`).toBe(hidden)
        expect(Math.abs(m.thTextRight - m.icons.at(-1)!.right), `${label}: header aligned with the icon group`).toBeLessThanOrEqual(2)
        expect(opaque(m.thBg), label).toBe(true)

        // Pixels: the cell's left padding strip is the row colour even with text scrolled beneath it.
        const samples = await pixels(page, [0.3, 0.5, 0.7].map((f) => ({ x: m.cell.left + 2, y: m.cell.top + m.cell.height * f })))
        const expected = rgb(m.rowBg).slice(0, 3)
        for (const sample of samples) {
          for (let channel = 0; channel < 3; channel++) expect(Math.abs(sample[channel] - expected[channel]), `${label}: pixel ${sample} vs ${expected}`).toBeLessThanOrEqual(3)
        }
      }
    }
  })
}

test('390 px: one "⋯" button in the sticky cell opens the same actions, with the reason for each unavailable one', async ({ page }) => {
  current = TABLES[0]
  await openBookings(page, 390, 'light')
  for (const fraction of [0, 0.5, 1]) {
    await scrollTo(page, fraction)
    const m = await measure(page, 0)
    expect(m.icons, 'only the menu button shows').toHaveLength(1)
    expect(Math.abs(m.cell.right - m.scroller.right)).toBeLessThanOrEqual(1)
    expect(m.hits[0]).toBe(true)
    expect(m.cell.width, 'the cell leaves most of the scroll area visible').toBeLessThan(m.scroller.width / 3)
  }

  const row = page.getByTestId('bookings-table').locator('tbody tr').first()
  const reference = (await row.locator('td').first().getByRole('link').textContent())!.trim()
  await row.getByRole('button', { name: `Actions — ${reference}` }).click()
  const sheet = page.getByRole('dialog', { name: `Actions — ${reference}` })
  await expect(sheet.getByRole('listitem')).toHaveCount(7)
  await expect(sheet.getByRole('button', { name: /PDF.*No invoice issued yet/ })).toBeDisabled()
  await sheet.getByRole('link', { name: /View/ }).click()
  await expect(page).toHaveURL(/\/bookings\/\d+$/)
})

test('row clicks open the booking but never swallow a click on an action; unavailable actions say why', async ({ page }) => {
  current = TABLES[0]
  await openBookings(page, 1440, 'light')
  const row = page.getByTestId('bookings-table').locator('tbody tr').first()
  const reference = (await row.locator('td').first().getByRole('link').textContent())!.trim()

  const pdf = row.getByRole('button', { name: `PDF — ${reference} (No invoice issued yet)` })
  await expect(pdf).toBeDisabled()
  await expect(pdf).toHaveAttribute('title', 'PDF: No invoice issued yet')
  await pdf.click({ force: true })
  await expect(page).toHaveURL(/\/bookings(\?.*)?$/)

  await expect(row.getByRole('link', { name: `WhatsApp — ${reference}` })).toHaveAttribute('href', /^https:\/\/wa\.me\/8801\d{9}$/)
  await expect(row.getByRole('button', { name: new RegExp(`^Delete — ${reference}$`) })).toBeEnabled()

  await row.locator('td').nth(2).click()
  await expect(page).toHaveURL(/\/bookings\/\d+$/)
})
