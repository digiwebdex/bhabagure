import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { controlClass, parseNumber } from '../../../components/ui/controls'
import { NumberInput, Switch } from '../../../components/ui/fields'
import { EMI_TENURES, type EmiRates } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'

type Tenure = (typeof EMI_TENURES)[number]

/**
 * Site settings → EMI rates (docs/emi-preview.md): SSLCommerz's chart, a percent per tenure for each bank group, shown
 * as an estimate under the website booking form's first step. An empty cell is a tenure that group doesn't offer
 * (Standard Chartered has no 30 months) — not a 0 % charge.
 */
export function EmiRatesEditor({ value, set, error }: { value: EmiRates; set: (value: EmiRates) => void; error: (field: string) => string | undefined }) {
  const { t } = useTranslation()
  const { locale, number, bdt } = useFormat()
  const setGroup = (index: number, patch: Partial<EmiRates['groups'][number]>) => set({ ...value, groups: value.groups.map((group, i) => (i === index ? { ...group, ...patch } : group)) })
  const setRate = (index: number, months: Tenure, rate: number | null) => setGroup(index, { rates: { ...value.groups[index].rates, [months]: rate } })
  const groupName = (group: EmiRates['groups'][number]) => (locale === 'en' ? group.name.en : group.name.bn || group.name.en)

  return (
    <>
      <Switch label={t('settings.emi.enabled')} checked={value.enabled} onChange={(enabled) => set({ ...value, enabled })} hint={t('settings.emi.enabledHint')} />
      <NumberInput label={t('settings.emi.minAmount')} value={value.minAmount} onChange={(minAmount) => set({ ...value, minAmount: minAmount ?? 0 })} error={error('value.minAmount')} preview={(amount) => bdt(amount)} />
      <div className="overflow-x-auto">
        <table className="w-full min-w-120 border-collapse text-13" data-testid="emi-rates">
          <thead>
            <tr>
              <th className="px-1.5 py-2 text-left font-semibold text-app-muted">{t('settings.emi.months')}</th>
              {value.groups.map((group) => (
                <th key={group.key} scope="col" className="px-1.5 py-2 text-left font-semibold">
                  {groupName(group)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {EMI_TENURES.map((months) => (
              <tr key={months} className="border-t border-app-line">
                <th scope="row" className="px-1.5 py-1.5 text-left font-display font-semibold">
                  {number(Number(months))}
                </th>
                {value.groups.map((group, index) => (
                  <td key={group.key} className="px-1.5 py-1.5">
                    <RateInput
                      label={t('settings.emi.rateLabel', { group: groupName(group), months: number(Number(months)) })}
                      value={group.rates[months]}
                      onChange={(rate) => setRate(index, months, rate)}
                      invalid={!!error(`value.groups.${index}.rates.${months}`)}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="m-0 text-12 text-app-muted">{t('settings.emi.rateHint')}</p>
      <div className="flex flex-col gap-3">
        {value.groups.map((group, index) => (
          <div key={group.key} className="flex flex-col gap-2 rounded-10 bg-app-surface-2 px-3 py-2.5">
            <span className="text-13 font-semibold">{groupName(group)}</span>
            <Switch label={t('settings.emi.groupShown')} checked={group.enabled} onChange={(enabled) => setGroup(index, { enabled })} />
            <Switch label={t('settings.emi.pending')} checked={group.eligibilityPending} onChange={(eligibilityPending) => setGroup(index, { eligibilityPending })} hint={t('settings.emi.pendingHint')} />
          </div>
        ))}
      </div>
      <p className="m-0 text-12 leading-1.55 text-app-muted">{t('settings.emi.formula')}</p>
    </>
  )
}

/** A percent, or empty for "not offered"; the typed text is kept as typed ("5." while typing 5.13). */
function RateInput({ label, value, onChange, invalid }: { label: string; value: number | null; onChange: (value: number | null) => void; invalid: boolean }) {
  const [draft, setDraft] = useState(value === null ? '' : String(value))
  if ((parseNumber(draft) ?? null) !== value) setDraft(value === null ? '' : String(value))

  return (
    <div className="w-22">
      <input
        type="text"
        inputMode="decimal"
        aria-label={label}
        aria-invalid={invalid}
        value={draft}
        placeholder="—"
        onChange={(event) => {
          setDraft(event.target.value)
          onChange(parseNumber(event.target.value) ?? null)
        }}
        className={`${controlClass(invalid)} text-right font-display`}
      />
    </div>
  )
}
