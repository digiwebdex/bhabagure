import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice } from '../../components/ui/feedback'
import { Card, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { useFormat } from '../../lib/useFormat'
import { CustomerAvatar } from '../invoices/CustomerAvatar'
import { ACCOUNT_SORTS, BALANCE_TABS, useCustomerAccounts, type AccountSort, type CustomerAccountFilters } from './api'

/** A number the old books never had: the import gave it a run of zeros, so it reads as missing rather than as a number. */
const isPlaceholderPhone =(phone: string) => /^0{6,}/.test(phone)

/**
 * Customers (docs/phase-9-accounts.md §9): everyone who has been invoiced, how many invoices, for how much, and what each
 * still owes — the Customers screen of the accounting service the client used before, beside the Invoices screen.
 */
export function CustomerAccountsPage() {
  const { t } = useTranslation()
  const { bdt, digits, month, number } = useFormat()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const pick = <T extends string>(value: string | null, allowed: readonly T[], fallback: T): T => (allowed.includes(value as T) ? (value as T) : fallback)
  const filters: CustomerAccountFilters = {
    balance: pick(params.get('balance'), BALANCE_TABS, 'all'),
    sort: pick(params.get('sort'), ACCOUNT_SORTS, 'name'),
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const list = useCustomerAccounts(filters)

  const set = (patch: Partial<CustomerAccountFilters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    if (next.balance !== 'all') query.set('balance', next.balance)
    if (next.sort !== 'name') query.set('sort', next.sort)
    if (next.search) query.set('search', next.search)
    if (next.page > 1) query.set('page', String(next.page))
    setParams(query, { replace: true })
  }

  return (
    <>
      <PageHeader
        title={t('customerAccounts.title')}
        subtitle={t('customerAccounts.subtitle')}
        actions={
          can('invoices.manage') ? (
            <Link to="/invoices/new" className={buttonClass('primary', 'sm')}>
              {t('invoices.create')}
            </Link>
          ) : undefined
        }
      />

      {/* The same bar the Invoices screen reads by. */}
      <div className="flex justify-center">
        <div className="flex flex-wrap justify-center gap-1 rounded-pill bg-linear-90/srgb from-blue to-blue-abyss p-1" role="tablist" aria-label={t('customerAccounts.title')}>
          {BALANCE_TABS.map((tab) => (
            <button
              key={tab}
              type="button"
              role="tab"
              aria-selected={filters.balance === tab}
              onClick={() => set({ balance: tab })}
              className={`cursor-pointer rounded-pill px-5 py-1.75 text-13 font-semibold transition-colors duration-150 ${filters.balance === tab ? 'bg-app-surface text-app-text' : 'bg-transparent text-white/85 hover:text-white'}`}
            >
              {t(`customerAccounts.tabs.${tab}`)}
              {list.data ? <span className="ml-1.5 font-display text-12 opacity-75">{list.data.meta.tabs[tab]}</span> : null}
            </button>
          ))}
        </div>
      </div>

      <Card>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="flex flex-col gap-1 text-12 text-app-muted">
            {t('customerAccounts.searchLabel')}
            <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('customerAccounts.search')} className={controlClass()} />
          </label>
          <label className="flex flex-col gap-1 text-12 text-app-muted">
            {t('customerAccounts.sortLabel')}
            <select value={filters.sort} onChange={(event) => set({ sort: event.target.value as AccountSort })} className={controlClass()}>
              {ACCOUNT_SORTS.map((sort) => (
                <option key={sort} value={sort}>
                  {t(`customerAccounts.sort.${sort}`)}
                </option>
              ))}
            </select>
          </label>
        </div>
      </Card>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-end gap-4 border-b border-app-line px-4 py-3 text-13">
          {list.data ? (
            <>
              <span className="text-app-muted">{t('customerAccounts.customers', { count: list.data.meta.total, n: number(list.data.meta.total) })}</span>
              <span className="text-app-muted">
                {t('invoices.invoiced')} <strong className="font-display text-app-text">{bdt(list.data.meta.totals.invoiced)}</strong>
              </span>
              <span className="text-app-muted">
                {t('invoices.outstanding')} <strong className="font-display text-app-text">{bdt(list.data.meta.totals.due)}</strong>
              </span>
            </>
          ) : null}
        </div>

        {list.isPending ? (
          <Loading />
        ) : list.isError ? (
          <div className="p-4">
            <ErrorNotice error={list.error} />
          </div>
        ) : list.data.data.length === 0 ? (
          filters.balance === 'due' && !filters.search ? <EmptyState title={t('customerAccounts.emptyDue')} /> : <EmptyState title={t('customerAccounts.empty')} note={t('customerAccounts.emptyNote')} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-max border-collapse text-13" data-testid="customer-accounts-table" aria-label={t('customerAccounts.title')}>
              <thead>
                <tr className="border-b border-app-line bg-app-surface-2 text-12 text-app-muted">
                  <th className="p-3 text-left font-semibold">{t('customerAccounts.columns.customer')}</th>
                  <th className="p-3 text-left font-semibold">{t('customerAccounts.columns.phone')}</th>
                  <th className="p-3 text-right font-semibold">{t('customerAccounts.columns.invoices')}</th>
                  <th className="p-3 text-right font-semibold">{t('customerAccounts.columns.total')}</th>
                  <th className="p-3 text-right font-semibold">{t('customerAccounts.columns.due')}</th>
                  <th className="p-3 text-left font-semibold">{t('customerAccounts.columns.since')}</th>
                  <th className="p-3 text-right font-semibold">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {list.data.data.map((row) => (
                  <tr key={row.id} className="border-b border-app-line last:border-0 hover:bg-app-surface-2">
                    <td className="p-3 align-top">
                      <span className="flex items-center gap-2">
                        <CustomerAvatar name={row.name} />
                        <span className="flex flex-col">
                          {can('customers.view') ? (
                            <Link to={`/customers/${row.id}`} className="font-medium">
                              {row.name}
                            </Link>
                          ) : (
                            <span className="font-medium">{row.name}</span>
                          )}
                          {row.email ? <span className="text-11 text-app-muted">{row.email}</span> : null}
                        </span>
                      </span>
                    </td>
                    <td className="p-3 align-top whitespace-nowrap">
                      {isPlaceholderPhone(row.phone) ? <span className="text-12 text-amber">{t('customerAccounts.noPhone')}</span> : <span className="font-display text-app-muted">{digits(row.phone.replace(/^88/, ''))}</span>}
                    </td>
                    <td className="p-3 text-right align-top font-display">{number(row.invoices)}</td>
                    <td className="p-3 text-right align-top font-display font-bold text-blue">{bdt(row.total)}</td>
                    <td className="p-3 text-right align-top">
                      <span className={`font-display ${row.due > 0 ? 'font-bold' : 'text-app-muted'}`}>{bdt(row.due)}</span>
                    </td>
                    <td className="p-3 align-top whitespace-nowrap">{month(row.customer_since)}</td>
                    <td className="p-3 text-right align-top">
                      {row.invoices > 0 ? (
                        <Link to={`/invoices?customer_id=${row.id}`} className={buttonClass('outline', 'sm')} aria-label={t('customerAccounts.viewInvoicesFor', { name: row.name })}>
                          {t('customerAccounts.viewInvoices')}
                        </Link>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {list.data && list.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: filters.page, last: list.data.meta.last_page })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= list.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>
    </>
  )
}
