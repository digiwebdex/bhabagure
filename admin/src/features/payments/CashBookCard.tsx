import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { contactActions } from '../../components/table/contactActions'
import { DataTable, type Column, type RowAction } from '../../components/table/DataTable'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Chips, EmptyState, Loading } from '../../components/ui/layout'
import { fetchDocument } from '../../lib/api/client'
import { useFormat } from '../../lib/useFormat'
import { paymentActions, useCashBook, usePaymentOptions, usePaymentsMutation, type CashBookFilters, type CashEntry } from './api'

const DIRECTIONS = ['all', 'in', 'out'] as const
const METHOD_GROUPS = ['bkash', 'nagad', 'sslcommerz', 'cash_bank', 'rocket'] as const
const blank: CashBookFilters = { direction: 'all', method: '', category: '', from: '', to: '', search: '', page: 1 }

/**
 * The cash book on the shared row-actions table (§3.3, ledger rows): ◉ the booking or deal, contact the party, ⎙ the
 * evidence, ✎ never (entries are not edited) and ✕ "Reverse…" with a reason where reversal is allowed.
 */
export function CashBookCard() {
  const { t } = useTranslation()
  const { bdt, dateTime } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()
  const [filters, setFilters] = useState<CashBookFilters>(blank)
  const book = useCashBook(filters)
  const [reversing, setReversing] = useState<CashEntry | null>(null)
  const set = (patch: Partial<CashBookFilters>) => setFilters({ ...filters, page: 1, ...patch })

  const openEvidence = async (entry: CashEntry) => {
    try {
      const blob = await fetchDocument(`admin/cash-book/${entry.id}/evidence`)
      window.open(URL.createObjectURL(blob), '_blank', 'noopener')
    } catch {
      toast(t('payments.evidenceFailed'), 'error')
    }
  }

  const label = (entry: CashEntry) => `#${entry.id}`
  const actionsFor = (entry: CashEntry): RowAction[] => [
    entry.booking
      ? { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', to: `/bookings/${entry.booking.id}` }
      : { key: 'view', icon: '◉', label: t('table.view'), tone: 'muted', disabledReason: entry.invoice ? t('payments.dealBelow') : t('payments.noLinkedRecord') },
    ...contactActions(t, {
      phone: entry.party?.phone,
      email: entry.party?.email,
      subject: t('payments.contactSubject', { reference: entry.booking?.reference ?? entry.invoice?.number ?? label(entry) }),
    }).map((action) => (entry.party ? action : { ...action, href: undefined, disabledReason: t('payments.noParty') })),
    { key: 'pdf', icon: '⎙', label: t('payments.evidence'), tone: 'amber', onSelect: () => void openEvidence(entry), disabledReason: entry.has_evidence ? undefined : t('payments.noEvidence') },
    { key: 'edit', icon: '✎', label: t('table.edit'), tone: 'muted', disabledReason: t('payments.neverEdited') },
    {
      key: 'reverse',
      icon: '✕',
      label: t('payments.reverse'),
      tone: 'red',
      onSelect: () => setReversing(entry),
      disabledReason: entry.actions.reverse ? undefined : t(`payments.reverseBlocked.${entry.reverse_blocked ?? 'permission'}`),
    },
  ]

  const columns: Column<CashEntry>[] = [
    { key: 'date', header: t('payments.date'), className: 'whitespace-nowrap text-13 text-app-muted', cell: (entry) => dateTime(entry.occurred_at) },
    {
      key: 'description',
      header: t('payments.description'),
      cell: (entry) => (
        <div className="flex max-w-80 flex-col">
          <span className="truncate font-medium">{entry.description}</span>
          <span className="truncate text-12 text-app-muted">
            {entry.booking ? (
              <Link to={`/bookings/${entry.booking.id}`} className="font-display">
                {entry.booking.reference}
              </Link>
            ) : entry.invoice ? (
              <span className="font-display">{entry.invoice.number}</span>
            ) : (
              t(`payments.category.${entry.category}`)
            )}
            {entry.party ? ` · ${entry.party.name}` : ''}
            {entry.business_line ? ` · ${t(`payments.line.${entry.business_line}`)}` : ''}
          </span>
          {entry.reverses_id || entry.reversed_by ? (
            <span className="text-11 font-semibold text-red">{entry.reverses_id ? t('payments.reversalOf', { id: entry.reverses_id }) : t('payments.reversedBy', { id: entry.reversed_by!.id })}</span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'method',
      header: t('bookings.method'),
      cell: (entry) => (
        <div className="flex flex-col">
          <span className="text-13">{t(`bookings.methods.${entry.method}`)}</span>
          {entry.reference ? <span className="font-display text-12 text-app-muted">{entry.reference}</span> : null}
          {entry.has_evidence ? <span className="text-11 font-semibold text-green">⎘ {t('payments.receipt')}</span> : null}
        </div>
      ),
    },
    { key: 'by', header: t('payments.recordedBy'), className: 'text-13 text-app-muted whitespace-nowrap', cell: (entry) => entry.recorded_by?.name ?? t('payments.system') },
    {
      key: 'amount',
      header: t('payments.inOut'),
      align: 'right',
      className: 'font-display font-semibold whitespace-nowrap',
      cell: (entry) => <span className={entry.direction === 'in' ? 'text-green' : 'text-amber'}>{entry.direction === 'in' ? bdt(entry.amount) : `− ${bdt(entry.amount)}`}</span>,
    },
  ]

  const categories = options.data ? [...new Set(['customer_payment', 'online_payment_charge', 'gateway_fee', ...options.data.categories.in, ...options.data.categories.out])] : []

  return (
    <Card padded={false} className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-app-line p-4">
        <CardTitle title="Cash book" aside={<span className="text-12 text-app-muted">{t('payments.appendOnly')}</span>} />
        <div className="flex flex-wrap items-end gap-3">
          <Chips label={t('payments.direction')} value={filters.direction} onChange={(direction) => set({ direction })} options={DIRECTIONS.map((value) => ({ value, label: t(`payments.directions.${value}`) }))} />
        </div>
        <div className="grid-auto-fit-160 grid gap-3">
          <SelectInput label={t('bookings.method')} value={filters.method} onChange={(method) => set({ method })} options={[{ value: '', label: t('common.all') }, ...METHOD_GROUPS.map((value) => ({ value, label: t(`payments.method.${value}`) }))]} />
          <SelectInput label={t('payments.categoryLabel')} value={filters.category} onChange={(category) => set({ category })} options={[{ value: '', label: t('common.all') }, ...categories.map((value) => ({ value, label: t(`payments.category.${value}`) }))]} />
          <TextInput label={t('payments.from')} type="date" value={filters.from} onChange={(from) => set({ from })} />
          <TextInput label={t('payments.to')} type="date" value={filters.to} onChange={(to) => set({ to })} />
        </div>
        <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('payments.search')} aria-label={t('payments.search')} className={controlClass()} />
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
        <DataTable label={t('payments.cashBook')} testId="cash-book-table" columns={columns} rows={book.data.data} rowKey={(entry) => entry.id} rowLabel={label} actions={actionsFor} />
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
      {reversing ? <ReverseDialog entry={reversing} onClose={() => setReversing(null)} /> : null}
    </Card>
  )
}

function ReverseDialog({ entry, onClose }: { entry: CashEntry; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reverse = usePaymentsMutation(() => paymentActions.reverse(entry.id, reason.trim()))

  return (
    <Dialog open onClose={onClose} title={t('payments.reverseTitle', { amount: bdt(entry.amount) })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('payments.reverseNote')}</p>
      <p className="m-0 text-14 font-medium">{entry.description}</p>
      <TextArea label={t('bookings.reason')} value={reason} onChange={setReason} rows={2} />
      {reverse.error ? <ErrorNotice error={reverse.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || reverse.isPending}
          onClick={() =>
            reverse.mutate(undefined, {
              onSuccess: () => {
                toast(t('payments.reversed'))
                onClose()
              },
            })
          }
        >
          {t('payments.reverse')}
        </button>
      </div>
    </Dialog>
  )
}
