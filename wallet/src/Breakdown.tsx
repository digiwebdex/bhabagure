import { useTranslation } from 'react-i18next'

import { useFormat } from './lib/format'
import type { Summary } from './lib/queries'
import { Card, Pending } from './ui'

/** Breakdown by source: what came in from each, and what went out, with a bar against the largest. */
export function Breakdown({ summary, error }: { summary: Summary | undefined; error?: unknown }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const rows = summary?.by_source ?? []
  const largest = Math.max(1, ...rows.map((row) => row.in))

  return (
    <Card>
      <h2 className="m-0 text-16 font-semibold">{t('breakdown.title')}</h2>
      {!summary ? (
        <Pending error={error} />
      ) : rows.length === 0 ? (
        <p className="m-0 text-13 text-wallet-muted">{t('breakdown.empty')}</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-3 p-0" data-testid="breakdown">
          {rows.map((row) => (
            <li key={row.name} className="flex flex-col gap-1.5">
              <span className="flex flex-wrap items-baseline justify-between gap-2 text-14">
                <span className="font-medium">{row.name}</span>
                <span className="flex gap-3 font-display text-13">
                  {row.out > 0 ? <span className="text-wallet-out-soft">− {bdt(row.out)}</span> : null}
                  <span className="text-wallet-in-soft">+ {bdt(row.in)}</span>
                </span>
              </span>
              <span className="h-1.5 overflow-hidden rounded-pill bg-wallet-bg">
                <span className="block h-full rounded-pill bg-gradient-to-r from-wallet-purple-deep to-wallet-violet" style={{ width: `${Math.round((row.in / largest) * 100)}%` }} />
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
