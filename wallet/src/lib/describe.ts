import { useTranslation } from 'react-i18next'

import { useFormat } from './format'
import type { Change, Entry } from './queries'

/** "Cash in", "Cash out", or a deal's advance or payment with the deal's name. */
export function useKindLabel() {
  const { t } = useTranslation()
  return (entry: Entry) => (entry.kind === 'entry' ? t(`cash.kinds.${entry.direction}`) : t(`entry.kinds.${entry.kind}`, { name: entry.source }))
}

/** One change an edit made, in words (docs/wallet-edits.md): "Amount: BDT 1,20,000 → BDT 1,00,000". */
export function useDescribeChange() {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  return (change: Change) => {
    const label = t(`entry.fields.${change.field}`)
    switch (change.field) {
      case 'amount':
        return `${label}: ${bdt(Number(change.before))} → ${bdt(Number(change.after))}`
      case 'direction':
        return `${label}: ${t(`cash.kinds.${change.before}`)} → ${t(`cash.kinds.${change.after}`)}`
      case 'occurred_on':
        return `${label}: ${date(String(change.before))} → ${date(String(change.after))}`
      case 'evidence':
        return change.before === null ? t('entry.evidenceAdded', { name: change.after }) : change.after === null ? t('entry.evidenceRemoved') : t('entry.evidenceReplaced', { name: change.after })
      default:
        return `${label}: “${change.before}” → “${change.after}”`
    }
  }
}
