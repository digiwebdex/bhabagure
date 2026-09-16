import { expect, test } from '@playwright/test'

import { artisan } from '../../scripts/e2e-api.mjs'
import { expectPdfTab, FIRST_LOAD, PHOTO, signIn } from './helpers'

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §6: last month's pay from attendance. A base salary is set, an allowance
 * added, the month finalised and one person paid with a receipt; the staff member finds the payslip under My attendance;
 * reversing the Salaries cash-out in the cash book leaves the month unpaid again.
 */
test.describe.configure({ mode: 'serial' })

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

/** Last month in Dhaka: the one normally being paid, and the Salary screen's default. */
const month = (() => {
  const [year, index] = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()).split('-').map(Number)
  const first = new Date(Date.UTC(year, index - 2, 1))
  return { value: `${first.getUTCFullYear()}-${String(first.getUTCMonth() + 1).padStart(2, '0')}`, label: `${MONTHS[first.getUTCMonth()]} ${first.getUTCFullYear()}` }
})()

const PERSON = 'E2E tour_operator'

test.beforeAll(() => {
  // The tour operator on a device of their own, punched in on every working day of last month (Friday off): the first
  // two at 11:20, late; the rest on time.
  artisan(
    'tinker',
    `--execute=$s = App\\Models\\Staff::query()->where('email', 'tour_operator@e2e.test')->firstOrFail(); $d = new App\\Models\\AttendanceDevice(['name' => 'Payroll e2e']); $d->forceFill(['token_hash' => str_repeat('e', 64), 'serial_number' => 'K40-PAYROLL-E2E'])->save(); $u = App\\Models\\AttendanceDeviceUser::query()->create(['attendance_device_id' => $d->id, 'device_user_id' => '77']); $u->forceFill(['staff_id' => $s->id])->save(); $n = 0; for ($day = Carbon\\CarbonImmutable::parse('${month.value}-01'); $day->format('Y-m') === '${month.value}'; $day = $day->addDay()) { if ($day->dayOfWeekIso === 5) { continue; } foreach ([$n < 2 ? '11:20:00' : '10:55:00', '19:05:00'] as $time) { App\\Models\\AttendancePunch::query()->create(['attendance_device_id' => $d->id, 'device_user_id' => '77', 'punched_at' => $day->toDateString().' '.$time, 'work_date' => $day->toDateString()]); } $n++; } echo 'ok';`,
  )
})

test('an admin sets a base salary, adds an allowance, finalises last month and pays it with a receipt', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/payroll')
  await expect(page.getByTestId('payroll-month')).toHaveText(`Salary · ${month.label}`, FIRST_LOAD)
  const row = page.getByTestId('payroll-table').locator('tbody tr').filter({ hasText: PERSON })
  await expect(row).toContainText('No salary')
  await expect(page.getByTestId('payroll-missing-salary')).toContainText(PERSON)
  await expect(row.getByRole('button', { name: `Adjust pay — ${PERSON} (Set a base salary first)` })).toBeDisabled()

  // A base salary from last month on, with its reason.
  await row.getByRole('button', { name: `Base salary — ${PERSON}` }).click()
  const salary = page.getByRole('dialog', { name: `Base salary · ${PERSON}` })
  await salary.getByLabel('Takes effect from').fill(month.value)
  await salary.getByLabel('Base monthly salary').fill('30000')
  await salary.getByLabel('Reason').fill('Joining salary')
  await salary.getByRole('button', { name: 'Save salary' }).click()
  await expect(salary.getByTestId('salary-history')).toContainText(`From ${month.label}`)
  await expect(salary.getByTestId('salary-history')).toContainText('BDT 30,000')
  await salary.getByRole('button', { name: 'Close' }).click()

  // Two late days at half pay: 30,000 − 30,000 ÷ 26 × 2 × 50 % = BDT 28,846, whatever the month's length.
  await expect(row).toContainText('BDT 28,846')
  await expect(row).toContainText('− BDT 1,153.85')

  await row.getByRole('button', { name: `Adjust pay — ${PERSON}` }).click()
  const adjust = page.getByRole('dialog', { name: `Adjust pay · ${PERSON} · ${month.label}` })
  await adjust.getByLabel('Amount').fill('1500')
  await adjust.getByLabel('Reason').fill('Mustang tour allowance')
  await expect(adjust).toContainText('Payable after this: BDT 30,346')
  await adjust.getByRole('button', { name: 'Add adjustment' }).click()
  await expect(page.getByTestId('payroll-adjustments')).toContainText('Mustang tour allowance')
  await expect(row).toContainText('+ BDT 1,500')
  await expect(row).toContainText('BDT 30,346')

  // The Attendance table shows the same pay in the design's salary columns.
  await page.getByRole('link', { name: 'Attendance and rules' }).click()
  const attendanceRow = page.getByTestId('attendance-month-table').locator('tbody tr').filter({ hasText: PERSON })
  await expect(attendanceRow).toContainText('BDT 30,000', FIRST_LOAD)
  await expect(attendanceRow).toContainText('BDT 30,346')
  await page.getByRole('link', { name: 'Salary sheet' }).click()

  await page.getByRole('button', { name: `Finalise ${month.label}` }).click(FIRST_LOAD)
  const finalise = page.getByRole('dialog', { name: `Finalise ${month.label}?` })
  await expect(finalise).toContainText('BDT 30,346 in total')
  await expect(finalise).toContainText('without a base salary')
  await finalise.getByRole('button', { name: 'Finalise', exact: true }).click()
  // The e2e queue is synchronous: the payslip is rendered and emailed inside this request.
  await expect(page.getByText('Month finalised. Payslips are on their way.')).toBeVisible({ timeout: 30_000 })
  await expect(row.getByText('Unpaid', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: `Finalise ${month.label}` })).toHaveCount(0)

  await row.getByRole('button', { name: `Mark paid — ${PERSON}` }).click()
  const pay = page.getByRole('dialog', { name: `Pay ${PERSON} · ${month.label}` })
  await expect(pay).toContainText('BDT 30,346')
  await pay.getByLabel('Method').selectOption({ label: 'bKash' })
  await pay.getByLabel('Reference').fill('TRX-E2E-PAY')
  await pay.getByLabel('Attach the receipt, bank slip or screenshot').setInputFiles(PHOTO)
  await pay.getByRole('button', { name: 'Mark paid' }).click()
  await expect(page.getByText(`${PERSON} is paid · in the cash book under Salaries`)).toBeVisible()
  await expect(row.getByText('Paid', { exact: true })).toBeVisible()
  await expect(row).toContainText('bKash')
  await expect(row.getByRole('button', { name: `Mark paid — ${PERSON} (Already paid)` })).toBeDisabled()

  await expectPdfTab(page, /\/payroll-items\/\d+\/payslip$/, () => row.getByRole('button', { name: `Payslip — ${PERSON}` }).click())
})

test('the staff member finds the paid month under My payslips, and has no Salary screen', async ({ page }) => {
  await signIn(page, 'tour_operator')
  await page.goto('/my-attendance')
  const payslips = page.getByTestId('my-payslips')
  await expect(payslips).toContainText(month.label, FIRST_LOAD)
  await expect(payslips).toContainText('BDT 30,346')
  await expect(payslips).toContainText('Paid')

  await expectPdfTab(page, /\/profile\/payslips\/\d+$/, () => payslips.getByRole('button', { name: `Open the payslip for ${month.label}` }).click())

  await expect(page.getByRole('navigation').getByRole('link', { name: /Salary/ })).toHaveCount(0)
  await page.goto('/payroll')
  await expect(page.getByText("You don't have access to this screen")).toBeVisible(FIRST_LOAD)
})

test('reversing the salary cash-out in the cash book leaves the month unpaid again', async ({ page }) => {
  await signIn(page, 'admin')
  await page.goto('/transactions')
  const entry = page.getByTestId('cash-book-table').locator('tbody tr').filter({ hasText: `Salary ${month.value} · ${PERSON}` })
  await expect(entry).toContainText('− BDT 30,346', FIRST_LOAD)
  await entry.getByRole('button', { name: /^Actions — #\d+$/ }).click()
  await page.getByRole('menuitem', { name: 'Reverse…' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Reason').fill('Sent to the wrong bKash number')
  await dialog.getByRole('button', { name: 'Reverse…' }).click()
  await expect(page.getByText('Reversing entry added')).toBeVisible()

  await page.goto(`/payroll?month=${month.value}`)
  const row = page.getByTestId('payroll-table').locator('tbody tr').filter({ hasText: PERSON })
  await expect(row.getByText('Unpaid', { exact: true })).toBeVisible(FIRST_LOAD)
  await expect(row.getByRole('button', { name: `Mark paid — ${PERSON}` })).toBeEnabled()
})
