import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { NumberInput, TextInput } from '../../../components/ui/fields'
import { ReorderButtons } from '../../../components/ui/layout'
import type { PriceOption } from '../../../lib/api/types'
import { move } from '../../../lib/move'
import { useFormat } from '../../../lib/useFormat'

const blank = (): PriceOption => ({ label_en: '', label_bn: '', extra_per_person: null, estimate_en: '', estimate_bn: '' })

/**
 * What the package costs beside its own price (docs/package-price-options.md), shown on the website under the prices for
 * two and four: an extra with an amount per person (a domestic flight, a resort day tour — the website adds it to the
 * package price), or a cost the agency can only estimate, in words (the international air ticket). One of the two.
 */
export function PriceOptionsField({ value, onChange, error }: { value: PriceOption[] | null; onChange: (options: PriceOption[] | null) => void; error: (field: string) => string | undefined }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const options = value ?? []
  const save = (next: PriceOption[]) => onChange(next.length === 0 ? null : next)
  const set = (index: number, patch: Partial<PriceOption>) => save(options.map((option, i) => (i === index ? { ...option, ...patch } : option)))

  return (
    <fieldset className="m-0 flex min-w-0 flex-col gap-2 rounded-12 border border-app-line p-3.5" data-testid="price-options">
      <legend className="px-1 text-14 font-semibold">{t('priceOptions.title')}</legend>
      <p className="m-0 text-12 text-app-muted">{t('priceOptions.note')}</p>
      {options.map((option, index) => (
        <div key={index} className="flex flex-wrap items-end gap-2 rounded-10 bg-app-surface-2 p-2.5">
          <div className="grid-auto-fit-200 grid min-w-0 flex-1 gap-2">
            <TextInput label={t('priceOptions.labelEn')} value={option.label_en} onChange={(label_en) => set(index, { label_en })} error={error(`price_options.${index}.label_en`) ?? error(`price_options.${index}`)} />
            <TextInput label={t('priceOptions.labelBn')} value={option.label_bn} onChange={(label_bn) => set(index, { label_bn })} error={error(`price_options.${index}.label_bn`)} />
            <NumberInput
              label={t('priceOptions.extra')}
              value={option.extra_per_person}
              onChange={(extra_per_person) => set(index, { extra_per_person })}
              error={error(`price_options.${index}.extra_per_person`)}
              preview={(amount) => t('packages.perPerson', { amount: bdt(amount) })}
            />
            <TextInput label={t('priceOptions.estimateEn')} value={option.estimate_en} onChange={(estimate_en) => set(index, { estimate_en })} error={error(`price_options.${index}.estimate_en`)} />
            <TextInput label={t('priceOptions.estimateBn')} value={option.estimate_bn} onChange={(estimate_bn) => set(index, { estimate_bn })} error={error(`price_options.${index}.estimate_bn`)} />
          </div>
          <span className="flex items-center gap-1.5 pb-1">
            <ReorderButtons index={index} count={options.length} label={option.label_en || t('priceOptions.option')} onMove={(from, to) => save(move(options, from, to))} />
            <button
              type="button"
              aria-label={t('common.remove')}
              className="size-6.5 cursor-pointer rounded-7 border border-app-line bg-transparent text-12 text-amber hover:border-red hover:text-red"
              onClick={() => save(options.filter((_, i) => i !== index))}
            >
              ×
            </button>
          </span>
        </div>
      ))}
      <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => save([...options, blank()])}>
        + {t('priceOptions.add')}
      </button>
    </fieldset>
  )
}
