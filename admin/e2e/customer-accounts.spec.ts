import { expect, test, type Page } from '@playwright/test'

import { FIRST_LOAD, signIn, staffApi } from './helpers'

/**
 * docs/phase-9-accounts.md §9: Accounting → Customers — everyone invoiced, for how much, and what each still owes — and
 * the same figures on the customer's own page, down to the payments against each invoice.
 */

/** A customer with one invoice of BDT 52,000, of which 20,000 has been paid. */
async function partPaidCustomer(page: Page, name: string): Promise<{ customerId: number; number: string }> {
  const admin = await staffApi(page, 'admin')
  const stamp = String(Date.now())
  const draft = await admin.post<{ data: { id: number; customer_id: number } }>('admin/invoices', {
    customer: { name, phone: `88019${stamp.slice(-8)}` },
    title: 'Nepal tour, two people',
    lines: [{ title: 'Nepal tour', quantity: 2, unit_price: 26000 }],
  })
  const issued = await admin.post<{ data: { number: string } }>(`admin/invoices/${draft.data.id}/issue`)
  await admin.postWithReceipt(`admin/deals/${draft.data.id}/payments`, { amount: 20000, method: 'cash', reference: `E2E-${stamp}` })

  return { customerId: draft.data.customer_id, number: issued.data.number }
}

test('the accountant finds who owes what under Accounting → Customers, and opens their invoices', async ({ page }) => {
  const name = `E2E Owing ${Date.now()}`
  const { number } = await partPaidCustomer(page, name)

  await signIn(page, 'accountant')
  await page.getByRole('navigation').getByRole('link', { name: 'C Customers', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Customers', level: 1 })).toBeVisible(FIRST_LOAD)

  await page.getByRole('tab', { name: /^With balance due/ }).click()
  await page.getByLabel('Find a customer').fill(name)
  const row = page.getByTestId('customer-accounts-table').locator('tbody tr').filter({ hasText: name })
  await expect(row).toHaveCount(1, FIRST_LOAD)
  await expect(row).toContainText('BDT 52,000')
  await expect(row).toContainText('BDT 32,000')

  // Sorted by who owes most, the list still holds them; the totals above follow the filter.
  await page.getByLabel('Sort by').selectOption('due')
  await expect(page.getByText('1 customer', { exact: true })).toBeVisible()

  await row.getByRole('link', { name: `Invoices for ${name}` }).click()
  await expect(page.getByRole('heading', { name: 'Invoices', level: 1 })).toBeVisible(FIRST_LOAD)
  await expect(page.getByTestId('invoices-table').locator('tbody tr').filter({ hasText: number })).toHaveCount(1, FIRST_LOAD)
  // Arriving from the customer, the filter names them rather than saying "This customer".
  await expect(page.getByRole('button', { name: 'Clear', exact: true }).locator('..')).toContainText(name)
})

test("a customer's page shows what they were invoiced, paid and still owe, invoice by invoice", async ({ page }) => {
  const name = `E2E Profile ${Date.now()}`
  const { customerId, number } = await partPaidCustomer(page, name)

  await signIn(page, 'admin')
  await page.goto(`/customers/${customerId}`)
  await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible(FIRST_LOAD)

  const balance = page.getByTestId('customer-balance')
  await expect(balance).toContainText('InvoicedBDT 52,000', FIRST_LOAD)
  await expect(balance).toContainText('PaidBDT 20,000')
  await expect(balance).toContainText('Balance dueBDT 32,000')

  const invoice = page.getByTestId('customer-invoices').locator('li').filter({ hasText: `#${number}` }).first()
  await expect(invoice).toContainText('Partial')
  await expect(invoice).toContainText('Nepal tour, two people')
  await expect(invoice).toContainText('Cash')

  // The invoice opens the same view the Invoices screen does.
  await invoice.getByRole('button', { name: `#${number}` }).click()
  await expect(page.getByRole('dialog', { name: 'Invoice details' }).getByTestId('invoice-payments')).toContainText('BDT 20,000', FIRST_LOAD)
})

test('staff who do not see payments get neither the screen nor the figures', async ({ page }) => {
  await signIn(page, 'sales_agent')
  await expect(page.getByRole('navigation').getByRole('link', { name: 'C Customers & leads' })).toBeVisible(FIRST_LOAD)
  await expect(page.getByRole('navigation').getByRole('link', { name: 'C Customers', exact: true })).toHaveCount(0)
})
