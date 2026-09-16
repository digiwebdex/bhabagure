import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'

import { useAuth } from '../../app/auth'
import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { Badge, Card, CardTitle, Chips, EmptyState, Loading, PageHeader } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { accountActions, useAccountAction, useChartOfAccounts, useJournal, type AccountRow, type JournalRow } from './api'

const KINDS = ['all', 'manual', 'system'] as const

/**
 * Journal entries (docs/phase-9-accounts.md §3): what the software posted for bookings, payments and invoices, and the
 * adjustments the accountant writes. Nothing is edited or deleted here — a wrong entry is reversed and both stay.
 */
export function JournalPage() {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const filters = {
    from: params.get('from') ?? '',
    to: params.get('to') ?? '',
    kind: params.get('kind') === 'manual' || params.get('kind') === 'system' ? params.get('kind')! : '',
    account_id: params.get('account_id') ?? '',
    search: params.get('search') ?? '',
    page: Math.max(1, Number(params.get('page')) || 1),
  }
  const entries = useJournal(filters)
  const chart = useChartOfAccounts()
  const [posting, setPosting] = useState(false)
  const [reversing, setReversing] = useState<JournalRow | null>(null)

  const set = (patch: Partial<typeof filters>) => {
    const next = { ...filters, page: 1, ...patch }
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries(next)) if (value && !(key === 'page' && value === 1)) query.set(key, String(value))
    setParams(query, { replace: true })
  }

  return (
    <>
      <PageHeader
        title={t('journal.title')}
        subtitle={t('journal.subtitle')}
        actions={
          can('journal.post') ? (
            <button type="button" className={buttonClass('primary', 'sm')} onClick={() => setPosting(true)}>
              {t('journal.add')}
            </button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-end gap-x-5 gap-y-2.5">
        <Chips
          label={t('journal.kind')}
          value={filters.kind || 'all'}
          onChange={(kind) => set({ kind: kind === 'all' ? '' : kind })}
          options={KINDS.map((value) => ({ value, label: t(`journal.kinds.${value}`) }))}
        />
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.from')}
          <input type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => set({ from: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.to')}
          <input type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => set({ to: event.target.value })} className={controlClass()} />
        </label>
        <label className="flex flex-col gap-1 text-12 text-app-muted">
          {t('journal.account')}
          <select value={filters.account_id} onChange={(event) => set({ account_id: event.target.value })} className={controlClass()}>
            <option value="">{t('journal.allAccounts')}</option>
            {(chart.data?.data ?? []).map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} · {account.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-app-line p-3.5">
          <CardTitle title={t('journal.cardTitle')} aside={entries.data ? <span className="text-12 text-app-muted">{t('journal.count', { count: entries.data.meta.total })}</span> : undefined} />
          <input type="search" value={filters.search} onChange={(event) => set({ search: event.target.value })} placeholder={t('journal.search')} aria-label={t('journal.search')} className={controlClass()} />
        </div>

        {entries.isPending ? (
          <Loading />
        ) : entries.isError ? (
          <div className="p-4">
            <ErrorNotice error={entries.error} />
          </div>
        ) : entries.data.data.length === 0 ? (
          <EmptyState title={t('journal.empty')} note={t('journal.emptyNote')} />
        ) : (
          <div className="overflow-x-auto" data-testid="journal-entries">
            <table className="w-full border-collapse text-13">
              <thead>
                <tr className="border-b border-app-line text-left text-12 text-app-muted">
                  <th className="px-4 py-2.5 font-semibold">{t('journal.columns.entry')}</th>
                  <th className="px-4 py-2.5 font-semibold">{t('journal.columns.account')}</th>
                  <th className="px-4 py-2.5 text-right font-semibold">{t('journal.columns.debit')}</th>
                  <th className="px-4 py-2.5 text-right font-semibold">{t('journal.columns.credit')}</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              {entries.data.data.map((entry) => (
                <tbody key={entry.id} className="border-b border-app-line last:border-b-0">
                  {entry.lines.map((line, index) => (
                    <tr key={`${entry.id}-${index}`}>
                      {index === 0 ? (
                        <td className="px-4 py-2.5 align-top" rowSpan={entry.lines.length}>
                          <span className="flex flex-col gap-0.5">
                            <span className="font-medium">{date(entry.date)}</span>
                            <span className="max-w-80 text-12 text-app-muted">{entry.description}</span>
                            <span className="flex flex-wrap items-center gap-1.5">
                              <span className="font-display text-11 text-app-muted">#{entry.id}</span>
                              {entry.manual ? <Badge tone="blue">{t('journal.manual')}</Badge> : null}
                              {entry.reverses ? <Badge tone="orange">{t('journal.reversal', { id: entry.reverses })}</Badge> : null}
                              {entry.reversed ? <Badge tone="slate">{t('journal.reversed')}</Badge> : null}
                            </span>
                          </span>
                        </td>
                      ) : null}
                      <td className="px-4 py-2.5">
                        <span className="font-display text-12 text-app-muted">{line.code}</span> {line.account}
                      </td>
                      <td className="px-4 py-2.5 text-right font-display">{line.debit ? bdt(line.debit) : ''}</td>
                      <td className="px-4 py-2.5 text-right font-display">{line.credit ? bdt(line.credit) : ''}</td>
                      {index === 0 ? (
                        <td className="px-4 py-2.5 text-right align-top" rowSpan={entry.lines.length}>
                          {entry.actions.reverse && can('journal.post') ? (
                            <button type="button" className={buttonClass('outline', 'sm', 'px-2.5 py-1 text-12')} onClick={() => setReversing(entry)}>
                              {t('journal.reverse')}
                            </button>
                          ) : null}
                        </td>
                      ) : null}
                    </tr>
                  ))}
                </tbody>
              ))}
            </table>
          </div>
        )}

        {entries.data && entries.data.meta.last_page > 1 ? (
          <div className="flex items-center justify-between gap-3 border-t border-app-line px-4 py-3 text-13">
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>
              {t('common.previous')}
            </button>
            <span className="text-app-muted">{t('common.pageOf', { page: filters.page, last: entries.data.meta.last_page })}</span>
            <button type="button" className={buttonClass('outline', 'sm')} disabled={filters.page >= entries.data.meta.last_page} onClick={() => set({ page: filters.page + 1 })}>
              {t('common.next')}
            </button>
          </div>
        ) : null}
      </Card>

      {posting ? <EntryDialog accounts={chart.data?.data ?? []} onClose={() => setPosting(false)} /> : null}
      {reversing ? <ReverseDialog entry={reversing} onClose={() => setReversing(null)} /> : null}
    </>
  )
}

type Line = { account_id: number | null; debit: number | null; credit: number | null }

function EntryDialog({ accounts, onClose }: { accounts: AccountRow[]; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const [entryDate, setEntryDate] = useState(todayInDhaka)
  const [description, setDescription] = useState('')
  const [lines, setLines] = useState<Line[]>([
    { account_id: null, debit: null, credit: null },
    { account_id: null, debit: null, credit: null },
  ])
  const post = useAccountAction(accountActions.post)
  const fieldError = (name: string) => (post.error instanceof ApiError ? post.error.field(name) : undefined)

  // Money accounts are not offered: cash moves through the cash book, never a journal entry.
  const choices = accounts.filter((account) => !account.is_money)
  const debits = lines.reduce((sum, line) => sum + (line.debit ?? 0), 0)
  const credits = lines.reduce((sum, line) => sum + (line.credit ?? 0), 0)
  const balanced = debits > 0 && Math.abs(debits - credits) < 0.005
  const setLine = (index: number, patch: Partial<Line>) => setLines((all) => all.map((line, i) => (i === index ? { ...line, ...patch } : line)))

  return (
    <Dialog open onClose={onClose} title={t('journal.addTitle')} wide>
      <div className="grid-auto-fit-200 grid gap-3">
        <TextInput label={t('journal.date')} type="date" value={entryDate} onChange={setEntryDate} max={todayInDhaka()} error={fieldError('entry_date')} />
        <TextArea label={t('journal.description')} value={description} onChange={setDescription} rows={1} error={fieldError('description')} />
      </div>

      <div className="flex flex-col gap-2" data-testid="journal-lines">
        {lines.map((line, index) => (
          <div key={index} className="grid gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <SelectInput
              label={t('journal.columns.account')}
              value={line.account_id === null ? '' : String(line.account_id)}
              onChange={(value) => setLine(index, { account_id: value ? Number(value) : null })}
              options={[{ value: '', label: t('common.choose') }, ...choices.map((account) => ({ value: String(account.id), label: `${account.code} · ${account.name}` }))]}
              error={fieldError(`lines.${index}.account_id`)}
            />
            <NumberInput
              label={t('journal.columns.debit')}
              value={line.debit}
              onChange={(debit) => setLine(index, { debit, credit: debit ? null : line.credit })}
              error={fieldError(`lines.${index}.debit`)}
            />
            <NumberInput
              label={t('journal.columns.credit')}
              value={line.credit}
              onChange={(credit) => setLine(index, { credit, debit: credit ? null : line.debit })}
              error={fieldError(`lines.${index}.credit`)}
            />
            <button
              type="button"
              className={buttonClass('outline', 'sm', `self-end px-2.5 py-2 text-12 ${index === 0 ? 'invisible' : ''}`)}
              disabled={lines.length <= 2}
              onClick={() => setLines((all) => all.filter((_, i) => i !== index))}
            >
              {t('common.remove')}
            </button>
          </div>
        ))}
        <button type="button" className={buttonClass('outline', 'sm', 'self-start')} onClick={() => setLines((all) => [...all, { account_id: null, debit: null, credit: null }])}>
          {t('journal.addLine')}
        </button>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2 rounded-10 border border-app-line px-3.5 py-2.5 text-13">
        <span className="flex gap-4">
          <span>
            {t('journal.columns.debit')}: <strong className="font-display">{bdt(debits)}</strong>
          </span>
          <span>
            {t('journal.columns.credit')}: <strong className="font-display">{bdt(credits)}</strong>
          </span>
        </span>
        <Badge tone={balanced ? 'green' : 'orange'}>{balanced ? t('journal.balanced') : t('journal.outOfBalance', { amount: bdt(Math.abs(debits - credits)) })}</Badge>
      </div>

      {post.error instanceof ApiError && post.error.field('lines') ? (
        <p role="alert" className="m-0 text-13 text-red">
          {post.error.field('lines')}
        </p>
      ) : null}
      {post.error && !(post.error instanceof ApiError && post.error.status === 422) ? <ErrorNotice error={post.error} /> : null}

      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!balanced || !description.trim() || post.isPending}
          onClick={() =>
            post.mutate(
              { entry_date: entryDate, description: description.trim(), lines: lines.filter((line) => line.account_id && (line.debit || line.credit)) },
              { onSuccess: () => { toast(t('journal.posted')); onClose() } },
            )
          }
        >
          {post.isPending ? t('common.saving') : t('journal.post')}
        </button>
      </div>
    </Dialog>
  )
}

function ReverseDialog({ entry, onClose }: { entry: JournalRow; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const reverse = useAccountAction(accountActions.reverse)

  return (
    <Dialog open onClose={onClose} title={t('journal.reverseTitle', { id: entry.id })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('journal.reverseNote')}</p>
      <TextArea label={t('journal.reason')} value={reason} onChange={setReason} rows={2} error={reverse.error instanceof ApiError ? reverse.error.field('reason') : undefined} />
      {reverse.error && !(reverse.error instanceof ApiError && reverse.error.status === 422) ? <ErrorNotice error={reverse.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || reverse.isPending}
          onClick={() => reverse.mutate({ id: entry.id, reason: reason.trim() }, { onSuccess: () => { toast(t('journal.reversedDone')); onClose() } })}
        >
          {reverse.isPending ? t('common.saving') : t('journal.reverse')}
        </button>
      </div>
    </Dialog>
  )
}
