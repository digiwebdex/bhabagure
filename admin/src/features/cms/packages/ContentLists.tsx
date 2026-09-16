import { useId, useState, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../../components/ui/button'
import { Pair, TextArea, TextInput } from '../../../components/ui/fields'
import { controlClass } from '../../../components/ui/controls'
import { ReorderButtons } from '../../../components/ui/layout'
import { move } from '../../../lib/move'
import type { Inclusion, ItineraryDay } from '../../../lib/api/types'
import { useFormat } from '../../../lib/useFormat'

type Errors = (path: string) => string | undefined

/** Day-by-day itinerary. Day numbers follow the order on screen, so reordering renumbers the days. */
export function ItineraryEditor({ days, onChange, error }: { days: ItineraryDay[]; onChange: (days: ItineraryDay[]) => void; error: Errors }) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const renumber = (list: ItineraryDay[]) => list.map((day, index) => ({ ...day, day_number: index + 1 }))
  const set = (index: number, patch: Partial<ItineraryDay>) => onChange(days.map((day, i) => (i === index ? { ...day, ...patch } : day)))

  return (
    <div className="flex flex-col gap-3">
      {days.map((day, index) => (
        <div key={index} className="flex flex-col gap-3 rounded-12 bg-app-surface-2 p-3.5">
          <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-10 bg-blue font-display text-15 font-extrabold text-white">{number(index + 1)}</span>
            <span className="flex-1 text-14 font-semibold">{t('packages.dayN', { n: number(index + 1) })}</span>
            <ReorderButtons index={index} count={days.length} label={t('packages.dayN', { n: number(index + 1) })} onMove={(from, to) => onChange(renumber(move(days, from, to)))} />
            <button type="button" className={buttonClass('danger', 'sm')} onClick={() => onChange(renumber(days.filter((_, i) => i !== index)))}>
              {t('common.remove')}
            </button>
          </div>
          <Pair>
            <TextInput label={t('fields.titleBn')} value={day.title_bn} onChange={(title_bn) => set(index, { title_bn })} error={error(`itinerary.${index}.title_bn`)} />
            <TextInput label={t('fields.titleEn')} value={day.title_en} onChange={(title_en) => set(index, { title_en })} error={error(`itinerary.${index}.title_en`)} placeholder="DHAKA ➔ KATHMANDU" />
          </Pair>
          <Pair>
            <TextArea label={t('packages.dayBodyBn')} rows={3} value={day.body_bn} onChange={(body_bn) => set(index, { body_bn })} error={error(`itinerary.${index}.body_bn`)} />
            <TextArea label={t('packages.dayBodyEn')} rows={3} value={day.body_en} onChange={(body_en) => set(index, { body_en })} error={error(`itinerary.${index}.body_en`)} />
          </Pair>
        </div>
      ))}
      <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => onChange([...days, { day_number: days.length + 1, title_bn: '', title_en: '', body_bn: '', body_en: '' }])}>
        + {t('packages.addDay')}
      </button>
    </div>
  )
}

/** Included / not included lines, one bilingual pair per line. */
export function InclusionsEditor({ items, onChange, error, path, addLabel }: { items: Inclusion[]; onChange: (items: Inclusion[]) => void; error: Errors; path: 'includes' | 'excludes'; addLabel: string }) {
  const { t } = useTranslation()
  const set = (index: number, patch: Partial<Inclusion>) => onChange(items.map((item, i) => (i === index ? { ...item, ...patch } : item)))

  return (
    <div className="flex flex-col gap-2">
      {items.map((item, index) => (
        <div key={index} className="flex flex-wrap items-end gap-2 rounded-10 bg-app-surface-2 p-2.5">
          <div className="grid-auto-fit-220 grid min-w-0 flex-1 gap-2">
            <TextInput label={t('fields.bangla')} value={item.text_bn} onChange={(text_bn) => set(index, { text_bn })} error={error(`${path}.${index}.text_bn`)} />
            <TextInput label={t('fields.english')} value={item.text_en} onChange={(text_en) => set(index, { text_en })} error={error(`${path}.${index}.text_en`)} />
          </div>
          <span className="flex items-center gap-1.5 pb-1">
            <ReorderButtons index={index} count={items.length} label={item.text_en || t('packages.line')} onMove={(from, to) => onChange(move(items, from, to))} />
            <button type="button" aria-label={t('common.remove')} className="size-6.5 cursor-pointer rounded-7 border border-app-line bg-transparent text-12 text-amber hover:border-red hover:text-red" onClick={() => onChange(items.filter((_, i) => i !== index))}>
              ×
            </button>
          </span>
        </div>
      ))}
      <button type="button" className={buttonClass('outline', 'sm', 'self-start border-dashed')} onClick={() => onChange([...items, { text_bn: '', text_en: '' }])}>
        + {addLabel}
      </button>
    </div>
  )
}

/** Free-text tags with suggestions from existing ones; Enter or comma adds. */
export function TagInput({ label, values, onChange, suggestions }: { label: string; values: string[]; onChange: (values: string[]) => void; suggestions: string[] }) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState('')
  const listId = useId()
  const add = (raw: string) => {
    const value = raw.trim()
    if (value && !values.some((existing) => existing.toLowerCase() === value.toLowerCase())) onChange([...values, value])
    setDraft('')
  }
  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault()
      add(draft)
    } else if (event.key === 'Backspace' && draft === '' && values.length > 0) {
      onChange(values.slice(0, -1))
    }
  }

  return (
    <div className="flex flex-col gap-1.25">
      <span className="text-13 text-app-muted">{label}</span>
      <div className="flex flex-wrap items-center gap-1.5 rounded-9 border border-app-line bg-app-surface-2 p-2">
        {values.map((value) => (
          <span key={value} className="flex items-center gap-1 rounded-pill bg-blue-tint py-0.5 pr-1 pl-2.5 text-12 font-semibold text-blue-deep">
            {value}
            <button type="button" aria-label={t('common.removeItem', { item: value })} className="size-4.5 cursor-pointer rounded-full bg-transparent text-12 text-blue-deep hover:bg-white" onClick={() => onChange(values.filter((v) => v !== value))}>
              ×
            </button>
          </span>
        ))}
        <input list={listId} value={draft} onChange={(event) => setDraft(event.target.value)} onKeyDown={onKeyDown} onBlur={() => draft && add(draft)} aria-label={label} placeholder={t('packages.tagPlaceholder')} className={`${controlClass()} w-40 flex-1 border-0 bg-transparent px-1 py-1 focus-visible:ring-0`} />
        <datalist id={listId}>
          {suggestions.filter((s) => !values.includes(s)).map((s) => (
            <option key={s} value={s} />
          ))}
        </datalist>
      </div>
    </div>
  )
}

/** Meta title and description per language, with length counters and the prototype's Google preview. */
export function SeoFields({ values, onChange, url, fallbackTitle }: {
  values: { seo_title_bn: string | null; seo_title_en: string | null; seo_description_bn: string | null; seo_description_en: string | null }
  onChange: (patch: Partial<{ seo_title_bn: string; seo_title_en: string; seo_description_bn: string; seo_description_en: string }>) => void
  url: string
  fallbackTitle: string
}) {
  const { t } = useTranslation()
  const { number } = useFormat()
  const counter = (value: string | null, max: number) => {
    const length = value?.length ?? 0
    return <span className={length > max ? 'text-amber' : undefined}>{t('seo.counter', { n: number(length), max: number(max) })}</span>
  }
  const previewTitle = values.seo_title_en || values.seo_title_bn || fallbackTitle
  const previewDescription = values.seo_description_en || values.seo_description_bn

  return (
    <div className="flex flex-col gap-3">
      <Pair>
        <TextInput label={t('seo.titleBn')} value={values.seo_title_bn} onChange={(seo_title_bn) => onChange({ seo_title_bn })} hint={counter(values.seo_title_bn, 60)} />
        <TextInput label={t('seo.titleEn')} value={values.seo_title_en} onChange={(seo_title_en) => onChange({ seo_title_en })} hint={counter(values.seo_title_en, 60)} />
      </Pair>
      <Pair>
        <TextArea label={t('seo.descriptionBn')} rows={2} value={values.seo_description_bn} onChange={(seo_description_bn) => onChange({ seo_description_bn })} hint={counter(values.seo_description_bn, 160)} />
        <TextArea label={t('seo.descriptionEn')} rows={2} value={values.seo_description_en} onChange={(seo_description_en) => onChange({ seo_description_en })} hint={counter(values.seo_description_en, 160)} />
      </Pair>
      <div className="flex flex-col gap-0.75 rounded-10 bg-app-surface-2 p-3">
        <span className="font-display text-12 text-app-muted">{t('seo.preview')}</span>
        <span className="font-display text-12 break-all text-green">{url}</span>
        <span className="text-16 leading-1.3 text-serp-link dark:text-blue-line">{previewTitle}</span>
        <span className="text-13 leading-1.4 text-app-muted">{previewDescription || t('seo.noDescription')}</span>
      </div>
    </div>
  )
}
