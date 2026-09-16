import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { Badge, Card, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import {
  paymentActions,
  useCashBook,
  usePaymentOptions,
  usePaymentsMutation,
  usePaymentsSummary,
  type CashBookFilters,
  type CashEntry,
} from '../payments/api'
import { PERIODS, periodRange, type Period } from './periods'
import { CashEntryDialog, ReverseDialog, TransferDialog, VatPaymentDialog } from './TransactionDialogs'
import { ReviewCard } from './ReviewCard'

type Dialog = { kind: 'in' | 'out' | 'transfer' | 'vat' } | { kind: 'reverse'; entry: CashEntry } | null

const blank: CashBookFilters = { direction: 'all', account: '', category: '', from: '', to: '', search: '', staff_id: '', approved: 'all', page: 1 }

/**
 * Transactions (docs/phase-9-accounts.md §7): the cash book, the account it is read for, and the four ways money is
 * written in by hand. It replaced the Payments screen — one cash book, in one place.
 *
 * Nothing here edits an entry. A mistake is corrected with a reversing entry, and the tick only records that somebody
 * has checked one; that is what makes the figures above it worth reading.
 */
export function TransactionsPage() {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const { can } = useAuth()
  const toast = useToast()
  const summary = usePaymentsSummary(new Date().toISOString().slice(0, 7))
  const options = usePaymentOptions()
  const [period, setPeriod] = useState<Period>('all')
  const [filters, setFilters] = useState<CashBookFilters>(blank)
  const book = useCashBook(filters)
  const [open, setOpen] = useState<Dialog>(null)
  const approve = usePaymentsMutation(({ id, approved }: { id: number; approved: boolean }) => paymentActions.approve(id, { approved }))

  const set = (patch: Partial<CashBookFilters>) => setFilters({ ...filters, page: 1, ...patch })
  const choosePeriod = (next: Period) => {
    setPeriod(next)
    if (next !== 'custom') set(periodRange(next))
  }

  const openEvidence = async (entry: CashEntry) => {
    try {
      const blob = await fetchDocument(`admin/cash-book/${entry.id}/evidence`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('payments.evidenceFailed'), 'error')
    }
  }

  const balance = summary.data?.balance ?? null
  const chosen = balance?.accounts.find((account) => account.code === filters.account)
  const accountLabel = chosen
    ? `${chosen.name_en || chosen.name_bn} ( ${bdt(chosen.balance)} )`
    : balance
      ? t('transactions.allAccounts', { amount: bdt(balance.total) })
      : t('transactions.allAccountsLoading')

  return (
    <>
      <PageHeader title={t('transactions.title')} subtitle={t('transactions.subtitle')} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* The account the list is read for, with what it holds — the company balance lives here. */}
        <label className="flex min-w-64 flex-col gap-1 text-12 text-app-muted">
          {t('transactions.account')}
          <select value={filters.account} onChange={(event) => set({ account: event.target.value })} className={controlClass()} aria-label={t('transactions.account')}>
            {/* Until the balance has arrived it is not named: "BDT 0" would say the company holds nothing. */}
            <option value="">{balance ? t('transactions.allAccounts', { amount: bdt(balance.total) }) : t('transactions.allAccountsLoading')}</option>
            {(balance?.accounts ?? []).map((account) => (
              <option key={account.code} value={account.code}>
                {account.name_en || account.name_bn} ( {bdt(account.balance)} )
              </option>
            ))}
          </select>
        </label>

        {can('transactions.create_manual') ? (
          <span className="flex flex-wrap gap-2">
            <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setOpen({ kind: 'in' })}>
              {t('transactions.cashIn')}
            </button>
            <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setOpen({ kind: 'out' })}>
              {t('transactions.cashOut')}
            </button>
            <button type="button" className={buttonClass('primary', 'sm')} disabled={(balance?.accounts.length ?? 0) < 2} onClick={() => setOpen({ kind: 'transfer' })}>
              {t('transactions.transfer')}
            </button>
            <MoreMenu onVat={() => setOpen({ kind: 'vat' })} />
          </span>
        ) : null}
      </div>

      {/* What the month came to, beside the balance: the Dashboard's Collected card leads here and must agree. */}
      <p className="m-0 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-13 text-app-muted">
        {can('ledger.view_company_balance') && balance ? <span>{accountLabel}</span> : null}
        <span>
          {t('payments.collected')} <strong className="font-display text-app-text" data-testid="payments-collected" title={bdt(summary.data?.collected ?? 0)}>{bdt(summary.data?.collected ?? 0)}</strong>
        </span>
        <span>
          {t('payments.invoicedThisMonth')} <strong className="font-display text-app-text">{bdt(summary.data?.invoiced ?? 0)}</strong>
        </span>
        <span>{t('transactions.appendOnly')}</span>
      </p>

      {/* SSLCommerz payments a person still has to look at. Shown only when there are any. */}
      <ReviewCard />

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-wrap items-end justify-between gap-3 border-b border-app-line p-4">
          <strong className="text-15">{t('transactions.title')}</strong>
          <span className="flex flex-wrap items-end gap-3">
            <label className="flex flex-col gap-1 text-12 text-app-muted">
              {t('transactions.period')}
              <select value={period} onChange={(event) => choosePeriod(event.target.value as Period)} className={controlClass()}>
                {PERIODS.map((value) => (
                  <option key={value} value={value}>
                    {t(`transactions.periods.${value}`)}
                  </option>
                ))}
              </select>
            </label>
            {period === 'custom' ? (
              <>
                <label className="flex flex-col gap-1 text-12 text-app-muted">
                  {t('payments.from')}
                  <input type="date" value={filters.from} onChange={(event) => set({ from: event.target.value })} className={controlClass()} />
                </label>
                <label className="flex flex-col gap-1 text-12 text-app-muted">
                  {t('payments.to')}
                  <input type="date" value={filters.to} onChange={(event) => set({ to: event.target.value })} className={controlClass()} />
                </label>
              </>
            ) : null}
            <label className="flex flex-col gap-1 text-12 text-app-muted">
              {t('transactions.checked')}
              <select value={filters.approved} onChange={(event) => set({ approved: event.target.value as CashBookFilters['approved'] })} className={controlClass()}>
                {(['all', 'no', 'yes'] as const).map((value) => (
                  <option key={value} value={value}>
                    {t(`transactions.checkedStates.${value}`)}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex min-w-48 flex-col gap-1 text-12 text-app-muted">
              {t('payments.search')}
              <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} className={controlClass()} />
            </label>
          </span>
        </div>

        {book.isPending ? (
          <Loading />
        ) : book.isError ? (
          <div className="p-4">
            <ErrorNotice error={book.error} />
          </div>
        ) : book.data.data.length === 0 ? (
          <EmptyState title={t('payments.emptyBook')} note={t('payments.emptyBookNote')} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-max border-collapse text-13" data-testid="cash-book-table" aria-label={t('transactions.title')}>
              <thead>
                <tr className="border-b border-app-line bg-app-surface-2 text-12 text-app-muted">
                  <th className="p-3 text-left font-semibold">{t('transactions.date')}</th>
                  <th className="p-3 text-left font-semibold">{t('transactions.description')}</th>
                  <th className="p-3 text-left font-semibold">{t('transactions.account')}</th>
                  <th className="p-3 text-left font-semibold">{t('transactions.category')}</th>
                  <th className="p-3 text-right font-semibold">{t('transactions.amount')}</th>
                  <th className="p-3 text-right font-semibold">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {book.data.data.map((entry) => (
                  <tr key={entry.id} className="border-b border-app-line last:border-0 hover:bg-app-surface-2">
                    <td className="p-3 align-top whitespace-nowrap">
                      <span className="flex flex-col gap-1">
                        <span>{date(entry.occurred_at)}</span>
                        <span className="flex flex-wrap items-center gap-1.5">
                          <span className="rounded-pill bg-app-surface-2 px-1.5 py-0.5 font-display text-11 text-app-muted">#{entry.id}</span>
                          <Badge tone={entry.direction === 'in' ? 'green' : 'red'}>{t(`transactions.directions.${entry.direction}`)}</Badge>
                        </span>
                        <span className="text-11 text-app-muted">{entry.recorded_by?.name ?? t('payments.system')}</span>
                      </span>
                    </td>
                    <td className="p-3 align-top">
                      <span className="flex max-w-80 flex-col">
                        <span className="truncate">{entry.description}</span>
                        {entry.booking ? (
                          <Link to={`/bookings/${entry.booking.id}`} className="font-display text-12">
                            {entry.booking.reference}
                          </Link>
                        ) : entry.invoice?.number ? (
                          <span className="font-display text-12 text-app-muted">{entry.invoice.number}</span>
                        ) : null}
                        {entry.reverses_id || entry.reversed_by ? (
                          <span className="text-11 font-semibold text-red">
                            {entry.reverses_id ? t('payments.reversalOf', { id: entry.reverses_id }) : t('payments.reversedBy', { id: entry.reversed_by!.id })}
                          </span>
                        ) : null}
                      </span>
                    </td>
                    <td className="p-3 align-top whitespace-nowrap">{entry.account?.name ?? t(`bookings.methods.${entry.method}`)}</td>
                    <td className="p-3 align-top whitespace-nowrap">{t(`payments.category.${entry.category}`, { defaultValue: entry.category })}</td>
                    <td className="p-3 text-right align-top">
                      <span className="flex items-center justify-end gap-1.5">
                        <span className={`font-display font-bold ${entry.direction === 'in' ? 'text-blue' : 'text-amber'}`}>
                          {entry.direction === 'in' ? bdt(entry.amount) : `− ${bdt(entry.amount)}`}
                        </span>
                        {entry.has_evidence ? (
                          <button type="button" aria-label={t('payments.evidence')} title={t('payments.evidence')} className="cursor-pointer border-0 bg-transparent p-0 text-13 text-app-muted" onClick={() => void openEvidence(entry)}>
                            🧾
                          </button>
                        ) : null}
                      </span>
                    </td>
                    <td className="p-3 align-top">
                      <span className="flex items-start justify-end gap-2">
                        {entry.actions.approve ? (
                          <button
                            type="button"
                            aria-label={entry.approved ? t('transactions.unapprove', { id: entry.id }) : t('transactions.approve', { id: entry.id })}
                            title={entry.approved ? t('transactions.approvedBy', { name: entry.approved.by ?? '' }) : t('transactions.approveHint')}
                            className={`flex size-7 cursor-pointer items-center justify-center rounded-pill border text-12 ${entry.approved ? 'border-green bg-green-tint text-green-deep' : 'border-app-line bg-transparent text-app-muted hover:border-green hover:text-green'}`}
                            onClick={() => approve.mutate({ id: entry.id, approved: !entry.approved })}
                          >
                            ✓
                          </button>
                        ) : null}
                        <RowMenu
                          entry={entry}
                          onEvidence={() => void openEvidence(entry)}
                          onReverse={() => setOpen({ kind: 'reverse', entry })}
                          reverseHint={t(`payments.reverseBlocked.${entry.reverse_blocked ?? 'permission'}`)}
                        />
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {book.data && book.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: filters.page, last: book.data.meta.last_page })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= book.data.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>

      {open?.kind === 'in' || open?.kind === 'out' ? <CashEntryDialog direction={open.kind} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'transfer' && balance ? <TransferDialog accounts={balance.accounts} onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'vat' ? <VatPaymentDialog onClose={() => setOpen(null)} /> : null}
      {open?.kind === 'reverse' ? <ReverseDialog entry={open.entry} onClose={() => setOpen(null)} /> : null}
      {options.isError ? <ErrorNotice error={options.error} /> : null}
    </>
  )
}

/** VAT and the journal, behind one button, as the client's books have them. */
function MoreMenu({ onVat }: { onVat: () => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const away = (event: MouseEvent) => {
      if (!box.current?.contains(event.target as Node)) setOpen(false)
    }
    const key = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', key)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', key)
    }
  }, [open])

  return (
    <div className="relative" ref={box}>
      <button type="button" aria-haspopup="menu" aria-expanded={open} className={buttonClass('primary', 'sm')} onClick={() => setOpen((was) => !was)}>
        {t('transactions.more')} ⌄
      </button>
      {open ? (
        <div role="menu" className="absolute top-9 right-0 z-30 flex min-w-40 flex-col rounded-10 border border-app-line bg-app-surface py-1 shadow-modal">
          <button type="button" role="menuitem" className="cursor-pointer border-0 bg-transparent px-3.5 py-1.75 text-left text-13 hover:bg-app-surface-2" onClick={() => { setOpen(false); onVat() }}>
            {t('transactions.vatTitle')}
          </button>
          <Link to="/journal" role="menuitem" className="px-3.5 py-1.75 text-left text-13 text-app-text hover:bg-app-surface-2">
            {t('transactions.journalEntry')}
          </Link>
        </div>
      ) : null}
    </div>
  )
}

/** What else a row can do. Editing is never one of them: the cash book is append-only. */
function RowMenu({ entry, onEvidence, onReverse, reverseHint }: { entry: CashEntry; onEvidence: () => void; onReverse: () => void; reverseHint: string }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const away = (event: MouseEvent) => {
      if (!box.current?.contains(event.target as Node)) setOpen(false)
    }
    const key = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', key)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('keydown', key)
    }
  }, [open])

  return (
    <div className="relative" ref={box}>
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={t('table.actionsFor', { name: `#${entry.id}` })}
        className="flex size-7 cursor-pointer items-center justify-center rounded-pill border border-blue bg-transparent text-12 text-blue hover:bg-blue-tint"
        onClick={() => setOpen((was) => !was)}
      >
        ⌄
      </button>
      {open ? (
        <div role="menu" className="absolute top-8 right-0 z-30 flex min-w-48 flex-col rounded-10 border border-app-line bg-app-surface py-1 shadow-modal">
          {entry.booking ? (
            <Link to={`/bookings/${entry.booking.id}`} role="menuitem" className="px-3.5 py-1.75 text-left text-13 text-app-text hover:bg-app-surface-2">
              {t('transactions.openBooking')}
            </Link>
          ) : null}
          <button
            type="button"
            role="menuitem"
            disabled={!entry.has_evidence}
            className="cursor-pointer border-0 bg-transparent px-3.5 py-1.75 text-left text-13 hover:bg-app-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
            onClick={() => { setOpen(false); onEvidence() }}
          >
            {t('transactions.viewAttachments')}
          </button>
          {/* Editing and deleting are what the client's books offer here. Ours never do, and the row says why. */}
          <span className="px-3.5 py-1.75 text-left text-12 text-app-muted">{t('payments.neverEdited')}</span>
          <button
            type="button"
            role="menuitem"
            disabled={!entry.actions.reverse}
            title={entry.actions.reverse ? undefined : reverseHint}
            className="cursor-pointer border-0 bg-transparent px-3.5 py-1.75 text-left text-13 text-red hover:bg-app-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
            onClick={() => { setOpen(false); onReverse() }}
          >
            {t('payments.reverse')}
          </button>
        </div>
      ) : null}
    </div>
  )
}
