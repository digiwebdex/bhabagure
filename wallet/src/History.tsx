import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { EntryDialog, EntryIcons, type EntryAction } from './EntryDialogs'
import { EvidenceIcon } from './icons'
import { openEvidence } from './lib/evidence'
import { useFormat } from './lib/format'
import { useHistory, type Direction, type Entry } from './lib/queries'
import { Card, Chip, Notice, Pending } from './ui'

/**
 * Transaction history, newest first, filtered by all, in or out. Each entry has View, Edit and Delete
 * (docs/wallet-edits.md); "Show edits and deletions" brings back what was there before an edit or a delete.
 */
export function History() {
  const { t } = useTranslation()
  const { bdt, date, number } = useFormat()
  const [direction, setDirection] = useState<Direction | 'all'>('all')
  const [page, setPage] = useState(1)
  const [withChanges, setWithChanges] = useState(false)
  const history = useHistory(direction, page, withChanges)
  const [open, setOpen] = useState<EntryAction | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [evidenceError, setEvidenceError] = useState(false)

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="m-0 text-16 font-semibold">{t('history.title')}</h2>
        <div className="flex flex-wrap gap-1.5">
          {(['all', 'in', 'out'] as const).map((value) => (
            <Chip
              key={value}
              on={direction === value}
              onClick={() => {
                setDirection(value)
                setPage(1)
              }}
            >
              {t(`history.filters.${value}`)}
            </Chip>
          ))}
          <Chip
            on={withChanges}
            onClick={() => {
              setWithChanges(!withChanges)
              setPage(1)
            }}
          >
            {t('history.showChanges')}
          </Chip>
        </div>
      </div>
      {done ? <Notice tone="in">{done}</Notice> : null}
      {evidenceError ? <Notice tone="out">{t('history.evidenceFailed')}</Notice> : null}
      {!history.data ? (
        <Pending error={history.error} />
      ) : history.data.data.length === 0 ? (
        <p className="m-0 text-13 text-wallet-muted">{t('history.empty')}</p>
      ) : (
        // A table from 640px up; on a phone each row folds into two lines (reference and amount, then date, source and the
        // icons), so View, Edit and Delete are always in sight without scrolling sideways.
        <div className="sm:overflow-x-auto">
          <table className="block w-full border-collapse text-13 sm:table sm:min-w-[600px]" data-testid="history">
            <thead className="hidden sm:table-header-group">
              <tr className="text-left font-display text-11 tracking-[0.06em] text-wallet-muted uppercase">
                <th className="border-b border-wallet-line px-2 py-2 font-semibold">{t('history.date')}</th>
                <th className="border-b border-wallet-line px-2 py-2 font-semibold">{t('history.reference')}</th>
                <th className="border-b border-wallet-line px-2 py-2 font-semibold">{t('history.source')}</th>
                <th className="border-b border-wallet-line px-2 py-2 text-right font-semibold">{t('history.amount')}</th>
                <th className="border-b border-wallet-line px-2 py-2 text-right font-semibold">{t('history.actions')}</th>
              </tr>
            </thead>
            <tbody className="block sm:table-row-group">
              {history.data.data.map((entry) => (
                <tr
                  key={entry.id}
                  className={`grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 border-b border-wallet-line py-2.5 sm:table-row sm:border-0 sm:py-0 ${entry.status === 'standing' ? '' : 'text-wallet-faint'}`}
                >
                  <td className="order-3 font-display text-12 text-wallet-muted sm:order-none sm:table-cell sm:border-b sm:border-wallet-line sm:px-2 sm:py-2.5 sm:text-13 sm:whitespace-nowrap">
                    {date(entry.occurred_on)}
                    <span className="font-sans sm:hidden"> · {entry.source}</span>
                  </td>
                  <td className="order-1 min-w-0 sm:order-none sm:table-cell sm:border-b sm:border-wallet-line sm:px-2 sm:py-2.5">
                    <span className="flex items-center gap-2">
                      <span aria-hidden className={`size-2 shrink-0 rounded-full ${entry.direction === 'in' ? 'bg-wallet-in' : 'bg-wallet-out'}`} />
                      <span className={entry.status === 'standing' ? '' : 'line-through'}>{entry.reference}</span>
                    </span>
                    <StatusLine entry={entry} />
                    {entry.has_evidence ? (
                      <button
                        type="button"
                        className="inline-flex cursor-pointer items-center gap-1 border-0 bg-transparent p-0 text-12 text-wallet-link"
                        onClick={() => void openEvidence(entry.id).then((opened) => setEvidenceError(!opened))}
                      >
                        <EvidenceIcon /> {t('history.evidence')}
                      </button>
                    ) : null}
                  </td>
                  <td className="hidden text-wallet-muted sm:table-cell sm:border-b sm:border-wallet-line sm:px-2 sm:py-2.5">{entry.source}</td>
                  <td
                    className={`order-2 text-right font-display font-bold whitespace-nowrap sm:order-none sm:table-cell sm:border-b sm:border-wallet-line sm:px-2 sm:py-2.5 ${
                      entry.status !== 'standing' ? 'line-through' : entry.direction === 'in' ? 'text-wallet-in-soft' : 'text-wallet-out-soft'
                    }`}
                  >
                    {entry.direction === 'in' ? '+' : '−'} {bdt(entry.amount)}
                  </td>
                  <td className="order-4 sm:order-none sm:table-cell sm:border-b sm:border-wallet-line sm:px-2 sm:py-2.5">
                    <EntryIcons entry={entry} onOpen={setOpen} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {history.data && history.data.meta.last_page > 1 ? (
        <div className="flex items-center justify-between gap-3 text-13 text-wallet-muted">
          <button type="button" className="cursor-pointer rounded-8 border border-wallet-input bg-transparent px-3 py-1.5 text-wallet-text disabled:opacity-40" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('history.previous')}
          </button>
          <span>{t('history.pageOf', { page: number(page), last: number(history.data.meta.last_page) })}</span>
          <button type="button" className="cursor-pointer rounded-8 border border-wallet-input bg-transparent px-3 py-1.5 text-wallet-text disabled:opacity-40" disabled={page >= history.data.meta.last_page} onClick={() => setPage(page + 1)}>
            {t('history.next')}
          </button>
        </div>
      ) : null}
      {open ? <EntryDialog action={open} onChange={setOpen} onClose={() => setOpen(null)} onDone={setDone} /> : null}
    </Card>
  )
}

/** Under the reference: when an entry was edited (a corrected one) or replaced or deleted, and why. */
function StatusLine({ entry }: { entry: Entry }) {
  const { t } = useTranslation()
  const { date } = useFormat()
  const reason = entry.change_reason ? ` · ${entry.change_reason}` : ''

  if (entry.status === 'edited') return <span className="block text-12 text-wallet-lilac">{t('history.replacedOn', { date: date(entry.changed_at ?? entry.occurred_on) }) + reason}</span>
  if (entry.status === 'deleted')
    return <span className="block text-12 text-wallet-out-soft">{t(entry.deal?.deleted ? 'history.deletedWithDeal' : 'history.deletedOn', { date: date(entry.changed_at ?? entry.occurred_on) }) + reason}</span>
  if (entry.replaces_id !== null) return <span className="block text-12 text-wallet-lilac">{t('history.editedOn', { date: date(entry.created_at ?? entry.occurred_on) })}</span>
  return null
}
