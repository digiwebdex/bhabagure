import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Card, CardTitle, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { ACCOUNT_TYPES, reportCsvUrl, useAccountTransactions, useChartOfAccounts, useGeneralLedger } from './api'

/** One account's entries with its running balance (docs/phase-9-accounts.md §4). */
export function AccountTransactionsPage() {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const chart = useChartOfAccounts()
  const [accountId, setAccountId] = useState<number | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const accounts = chart.data?.data ?? []
  const chosen = accountId ?? accounts.find((account) => account.is_money)?.id ?? accounts[0]?.id ?? null
  const report = useAccountTransactions({ account_id: chosen, from, to })

  return (
    <>
      <PageHeader title={t('reports.accountTransactions')} subtitle={t('reports.accountTransactionsNote')} />

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex min-w-60 flex-col gap-1 text-12 text-app-muted">
          {t('journal.account')}
          <select value={chosen ?? ''} onChange={(event) => setAccountId(Number(event.target.value))} className={controlClass()}>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} · {account.name}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.from')}
          <input type="date" value={from} max={to || undefined} onChange={(event) => setFrom(event.target.value)} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.to')}
          <input type="date" value={to} min={from || undefined} onChange={(event) => setTo(event.target.value)} className={controlClass()} />
        </label>
        <CsvButton path="admin/reports/account-transactions" params={{ account_id: chosen, from, to }} name={`account-${chosen ?? ''}.csv`} />
      </div>

      {report.isPending ? (
        <Loading />
      ) : report.isError ? (
        <ErrorNotice error={report.error} />
      ) : (
        <Card padded={false} className="overflow-hidden">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-app-line p-3.5">
            <CardTitle title={`${report.data.meta.account.code} · ${report.data.meta.account.name}`} />
            <span className="flex flex-wrap gap-4 text-13">
              <span className="text-app-muted">
                {t('reports.opening')} <strong className="font-display text-app-text">{bdt(report.data.meta.opening)}</strong>
              </span>
              <span className="text-app-muted">
                {t('reports.closing')} <strong className="font-display text-app-text">{bdt(report.data.meta.closing)}</strong>
              </span>
            </span>
          </div>
          {report.data.data.length === 0 ? (
            <EmptyState title={t('reports.noEntries')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-13" data-testid="account-transactions">
                <thead>
                  <tr className="border-b border-app-line text-left text-12 text-app-muted">
                    <th className="px-4 py-2.5 font-semibold">{t('journal.columns.entry')}</th>
                    <th className="px-4 py-2.5 font-semibold">{t('journal.description')}</th>
                    <th className="px-4 py-2.5 text-right font-semibold">{t('journal.columns.debit')}</th>
                    <th className="px-4 py-2.5 text-right font-semibold">{t('journal.columns.credit')}</th>
                    <th className="px-4 py-2.5 text-right font-semibold">{t('reports.balance')}</th>
                  </tr>
                </thead>
                <tbody>
                  {report.data.data.map((row) => (
                    <tr key={`${row.entry_id}-${row.date}-${row.debit}-${row.credit}`} className="border-b border-app-line last:border-b-0">
                      <td className="px-4 py-2.5 whitespace-nowrap">
                        <span className="flex flex-col">
                          <span>{date(row.date)}</span>
                          <span className="font-display text-11 text-app-muted">#{row.entry_id}</span>
                        </span>
                      </td>
                      <td className="max-w-96 px-4 py-2.5">
                        {row.description}
                        {row.is_reversal ? <Badge tone="orange">{t('journal.reversalShort')}</Badge> : null}
                      </td>
                      <td className="px-4 py-2.5 text-right font-display">{row.debit ? bdt(row.debit) : ''}</td>
                      <td className="px-4 py-2.5 text-right font-display">{row.credit ? bdt(row.credit) : ''}</td>
                      <td className="px-4 py-2.5 text-right font-display font-bold">{bdt(row.balance)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </>
  )
}

/** Every account's movement in a period, with the totals that must agree (§4). */
export function GeneralLedgerPage() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const report = useGeneralLedger({ from, to })

  return (
    <>
      <PageHeader title={t('reports.generalLedger')} subtitle={t('reports.generalLedgerNote')} />

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.from')}
          <input type="date" value={from} max={to || undefined} onChange={(event) => setFrom(event.target.value)} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.to')}
          <input type="date" value={to} min={from || undefined} onChange={(event) => setTo(event.target.value)} className={controlClass()} />
        </label>
        <CsvButton path="admin/reports/general-ledger" params={{ from, to }} name="general-ledger.csv" />
      </div>

      {report.isPending ? (
        <Loading />
      ) : report.isError ? (
        <ErrorNotice error={report.error} />
      ) : (
        <Card padded={false} className="overflow-hidden">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-app-line p-3.5">
            <CardTitle title={t('reports.generalLedger')} />
            <span className="flex gap-4 text-13">
              <span className="text-app-muted">
                {t('journal.columns.debit')} <strong className="font-display text-app-text">{bdt(report.data.meta.totals.debit)}</strong>
              </span>
              <span className="text-app-muted">
                {t('journal.columns.credit')} <strong className="font-display text-app-text">{bdt(report.data.meta.totals.credit)}</strong>
              </span>
              <Badge tone={Math.abs(report.data.meta.totals.debit - report.data.meta.totals.credit) < 0.005 ? 'green' : 'red'}>
                {Math.abs(report.data.meta.totals.debit - report.data.meta.totals.credit) < 0.005 ? t('reports.agrees') : t('reports.disagrees')}
              </Badge>
            </span>
          </div>
          {report.data.data.length === 0 ? (
            <EmptyState title={t('reports.noEntries')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-13" data-testid="general-ledger">
                <thead>
                  <tr className="border-b border-app-line text-left text-12 text-app-muted">
                    <th className="px-4 py-2.5 font-semibold">{t('accounts.code')}</th>
                    <th className="px-4 py-2.5 font-semibold">{t('accounts.name')}</th>
                    <th className="px-4 py-2.5 text-right font-semibold">{t('journal.columns.debit')}</th>
                    <th className="px-4 py-2.5 text-right font-semibold">{t('journal.columns.credit')}</th>
                    <th className="px-4 py-2.5 text-right font-semibold">{t('reports.balance')}</th>
                  </tr>
                </thead>
                {ACCOUNT_TYPES.map((type) => {
                  const rows = report.data.data.filter((row) => row.type === type)
                  if (rows.length === 0) return null
                  return (
                    <tbody key={type}>
                      <tr className="border-b border-app-line bg-app-surface-2">
                        <td colSpan={5} className="px-4 py-2 text-12 font-semibold text-app-muted uppercase">
                          {t(`accounts.types.${type}`)}
                        </td>
                      </tr>
                      {rows.map((row) => (
                        <tr key={row.id} className="border-b border-app-line last:border-b-0">
                          <td className="px-4 py-2.5 font-display text-12 text-app-muted">{row.code}</td>
                          <td className="px-4 py-2.5">{row.name}</td>
                          <td className="px-4 py-2.5 text-right font-display">{row.debit ? bdt(row.debit) : ''}</td>
                          <td className="px-4 py-2.5 text-right font-display">{row.credit ? bdt(row.credit) : ''}</td>
                          <td className="px-4 py-2.5 text-right font-display font-bold">{bdt(row.balance)}</td>
                        </tr>
                      ))}
                    </tbody>
                  )
                })}
              </table>
            </div>
          )}
        </Card>
      )}
    </>
  )
}

/** Downloads the report as CSV with the session's token, then hands the file to the browser. */
function CsvButton({ path, params, name }: { path: string; params: Record<string, string | number | null>; name: string }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [busy, setBusy] = useState(false)

  return (
    <button
      type="button"
      className={buttonClass('outline', 'md', 'self-end')}
      disabled={busy}
      onClick={async () => {
        setBusy(true)
        try {
          const blob = await fetchDocument(reportCsvUrl(path, params))
          const url = URL.createObjectURL(blob)
          const link = Object.assign(document.createElement('a'), { href: url, download: name })
          document.body.append(link)
          link.click()
          link.remove()
          setTimeout(() => URL.revokeObjectURL(url), 60_000)
        } catch {
          toast(t('reports.csvFailed'), 'error')
        }
        setBusy(false)
      }}
    >
      {busy ? t('common.saving') : t('reports.csv')}
    </button>
  )
}
