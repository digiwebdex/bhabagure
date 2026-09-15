import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { api, ApiError } from './lib/api'
import { useFormat } from './lib/format'
import { actions, useBookChange, useHistory, type Direction, type Entry } from './lib/queries'
import { Card, Chip, Notice, Pending, PrimaryButton, inputClass } from './ui'

/**
 * Transaction history, newest first, filtered by all, in or out. The prototype's ✕ is Reverse with a reason: an entry
 * the other way, so nothing is ever deleted.
 */
export function History() {
  const { t } = useTranslation()
  const { bdt, date, number } = useFormat()
  const [direction, setDirection] = useState<Direction | 'all'>('all')
  const [page, setPage] = useState(1)
  const history = useHistory(direction, page)
  const [reversing, setReversing] = useState<Entry | null>(null)
  const [evidenceError, setEvidenceError] = useState(false)

  const openEvidence = async (entry: Entry) => {
    const tab = window.open('', '_blank')
    try {
      const url = URL.createObjectURL(await api.blob(`transactions/${entry.id}/evidence`))
      if (tab) tab.location.href = url
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
      setEvidenceError(false)
    } catch {
      tab?.close()
      setEvidenceError(true)
    }
  }

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="m-0 text-16 font-semibold">{t('history.title')}</h2>
        <div className="flex gap-1.5">
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
        </div>
      </div>
      {evidenceError ? <Notice tone="out">{t('history.evidenceFailed')}</Notice> : null}
      {!history.data ? (
        <Pending error={history.error} />
      ) : history.data.data.length === 0 ? (
        <p className="m-0 text-13 text-wallet-muted">{t('history.empty')}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[520px] border-collapse text-13" data-testid="history">
            <thead>
              <tr className="text-left font-display text-11 tracking-[0.06em] text-wallet-muted uppercase">
                <th className="border-b border-wallet-line px-2 py-2 font-semibold">{t('history.date')}</th>
                <th className="border-b border-wallet-line px-2 py-2 font-semibold">{t('history.reference')}</th>
                <th className="border-b border-wallet-line px-2 py-2 font-semibold">{t('history.source')}</th>
                <th className="border-b border-wallet-line px-2 py-2 text-right font-semibold">{t('history.amount')}</th>
                <th className="border-b border-wallet-line px-2 py-2">
                  <span className="sr-only">{t('history.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {(history.data?.data ?? []).map((entry) => (
                <tr key={entry.id} className={entry.reversed || entry.kind === 'reversal' ? 'text-wallet-faint' : ''}>
                  <td className="border-b border-wallet-line px-2 py-2.5 font-display whitespace-nowrap text-wallet-muted">{date(entry.occurred_on)}</td>
                  <td className="border-b border-wallet-line px-2 py-2.5">
                    <span className="flex items-center gap-2">
                      <span aria-hidden className={`size-2 shrink-0 rounded-full ${entry.direction === 'in' ? 'bg-wallet-in' : 'bg-wallet-out'}`} />
                      <span className={entry.reversed ? 'line-through' : ''}>{entry.reference}</span>
                    </span>
                    {entry.kind === 'reversal' ? <span className="block text-12 text-wallet-lilac">{t('history.reversalOf', { reason: entry.reason ?? '' })}</span> : null}
                    {entry.has_evidence ? (
                      <button type="button" className="cursor-pointer border-0 bg-transparent p-0 text-12 text-wallet-link" onClick={() => void openEvidence(entry)}>
                        ⎘ {t('history.evidence')}
                      </button>
                    ) : null}
                  </td>
                  <td className="border-b border-wallet-line px-2 py-2.5 text-wallet-muted">{entry.source}</td>
                  <td className={`border-b border-wallet-line px-2 py-2.5 text-right font-display font-bold whitespace-nowrap ${entry.direction === 'in' ? 'text-wallet-in-soft' : 'text-wallet-out-soft'}`}>
                    {entry.direction === 'in' ? '+' : '−'} {bdt(entry.amount)}
                  </td>
                  <td className="border-b border-wallet-line px-2 py-2.5 text-right">
                    {entry.kind !== 'reversal' && !entry.reversed ? (
                      <button
                        type="button"
                        className="cursor-pointer rounded-8 border border-wallet-input bg-transparent px-2 py-1 text-12 font-semibold text-wallet-out-soft"
                        onClick={() => setReversing(entry)}
                        aria-label={t('history.reverseNamed', { reference: entry.reference })}
                      >
                        {t('history.reverse')}
                      </button>
                    ) : entry.reversed ? (
                      <span className="text-11">{t('history.reversed')}</span>
                    ) : null}
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
      {reversing ? <ReverseDialog entry={reversing} onClose={() => setReversing(null)} /> : null}
    </Card>
  )
}

function ReverseDialog({ entry, onClose }: { entry: Entry; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const [reason, setReason] = useState('')
  const reverse = useBookChange(actions.reverse)

  return (
    <div role="dialog" aria-modal="true" aria-label={t('history.reverseTitle', { amount: bdt(entry.amount) })} className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div className="flex w-full max-w-md flex-col gap-3 rounded-18 border border-wallet-line bg-wallet-surface p-5" onClick={(event) => event.stopPropagation()}>
        <h2 className="m-0 text-17 font-semibold">{t('history.reverseTitle', { amount: bdt(entry.amount) })}</h2>
        <p className="m-0 text-13 leading-1.55 text-wallet-muted">{t('history.reverseNote', { reference: entry.reference })}</p>
        <label className="flex flex-col gap-1.5 text-13 text-wallet-muted">
          {t('history.reason')}
          <textarea className={`${inputClass} min-h-20`} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} />
        </label>
        {reverse.error ? <Notice tone="out">{reverse.error instanceof ApiError ? reverse.error.message : String(reverse.error)}</Notice> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className="cursor-pointer rounded-12 border border-wallet-input bg-transparent px-4 py-2.5 text-14 font-semibold text-wallet-text" onClick={onClose}>
            {t('history.cancel')}
          </button>
          <PrimaryButton tone="out" disabled={reason.trim().length < 3 || reverse.isPending} onClick={() => reverse.mutate({ id: entry.id, reason: reason.trim() }, { onSuccess: onClose })}>
            {t('history.reverse')}
          </PrimaryButton>
        </div>
      </div>
    </div>
  )
}
