import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { controlClass } from '../../components/ui/controls'
import { EvidenceInput, evidenceReady } from '../../components/ui/EvidenceInput'
import { ErrorNotice, useToast } from '../../components/ui/feedback'
import { NumberInput, SelectInput, TextInput } from '../../components/ui/fields'
import { Card, CardTitle, Chips, Loading } from '../../components/ui/layout'
import { ApiError } from '../../lib/api/client'
import { todayInDhaka, useFormat } from '../../lib/useFormat'
import { paymentActions, usePaymentOptions, usePaymentsMutation, usePresets } from './api'

type Direction = 'in' | 'out'

/**
 * Manual cash in / out (§4.6, question 3): a money account (from the method), a category that decides the journal's
 * other side, the business line as a tag, a saved or typed reference, and an optional receipt. Posted once; a mistake
 * is reversed from the cash book.
 */
export function ManualEntryCard() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const toast = useToast()
  const options = usePaymentOptions()
  const presets = usePresets()
  const blank = { direction: 'out' as Direction, method: 'cash', category: '', business_line: '', description: '', reference: '', amount: null as number | null, occurred_on: todayInDhaka() }
  const [form, setForm] = useState(blank)
  const [evidence, setEvidence] = useState<File | null>(null)
  const [newPreset, setNewPreset] = useState('')

  const post = usePaymentsMutation(() => {
    const body = new FormData()
    for (const [key, value] of Object.entries(form)) if (value !== null && value !== '') body.append(key, String(value))
    if (evidence) body.append('evidence', evidence)
    return paymentActions.createEntry(body)
  })
  const addPreset = usePaymentsMutation(() => paymentActions.addPreset({ label: newPreset.trim(), direction: form.direction }))
  const removePreset = usePaymentsMutation((id: number) => paymentActions.removePreset(id))

  if (options.isPending) return <Card><Loading /></Card>
  if (options.isError) return <Card><ErrorNotice error={options.error} /></Card>

  const categories = options.data.categories[form.direction]
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })
  const fieldError = (name: string) => (post.error instanceof ApiError ? post.error.field(name) : undefined)
  const ready = !!form.category && form.description.trim().length >= 3 && (form.amount ?? 0) >= 1 && !!evidence && evidenceReady(evidence)

  return (
    <Card>
      <CardTitle bn="ম্যানুয়াল লেনদেন" en="Manual cash in / out" />
      <form
        className="flex flex-col gap-3"
        data-testid="manual-entry"
        onSubmit={(event) => {
          event.preventDefault()
          if (!ready) return
          post.mutate(undefined, {
            onSuccess: () => {
              toast(t('payments.postedWithEvidence', { name: evidence?.name ?? '' }))
              setForm({ ...blank, direction: form.direction, method: form.method })
              setEvidence(null)
            },
          })
        }}
      >
        <Chips label={t('payments.direction')} value={form.direction} onChange={(direction) => set({ direction, category: '' })} options={(['in', 'out'] as const).map((value) => ({ value, label: t(`payments.directions.${value}`) }))} />
        <div className="grid-auto-fit-half-140 grid gap-3">
          <SelectInput label={t('bookings.method')} value={form.method} onChange={(method) => set({ method })} options={options.data.methods.map((value) => ({ value, label: t(`bookings.methods.${value}`) }))} error={fieldError('method')} />
          <SelectInput
            label={t('payments.categoryLabel')}
            value={form.category}
            onChange={(category) => set({ category })}
            options={[{ value: '', label: t('common.choose') }, ...categories.map((value) => ({ value, label: t(`payments.category.${value}`) }))]}
            error={fieldError('category')}
          />
        </div>
        <SelectInput label={t('payments.source')} value={form.business_line} onChange={(business_line) => set({ business_line })} options={[{ value: '', label: '—' }, ...options.data.business_lines.map((value) => ({ value, label: t(`payments.line.${value}`) }))]} />

        <div className="flex flex-col gap-1.75">
          <span className="text-12 text-app-muted">{t('payments.presets')}</span>
          <div className="flex flex-wrap gap-1.5">
            {(presets.data ?? [])
              .filter((preset) => preset.direction === null || preset.direction === form.direction)
              .map((preset) => (
                <span key={preset.id} className={`flex items-center gap-1 rounded-pill border px-2.75 py-1 text-12 font-semibold ${form.description === preset.label ? 'border-blue bg-blue text-white' : 'border-app-line text-app-text'}`}>
                  <button type="button" className="cursor-pointer border-0 bg-transparent p-0 font-semibold text-inherit" onClick={() => set({ description: preset.label, reference: form.reference })}>
                    {preset.label}
                  </button>
                  <button type="button" className="cursor-pointer border-0 bg-transparent p-0 text-inherit opacity-50" aria-label={t('payments.removePreset', { label: preset.label })} onClick={() => removePreset.mutate(preset.id)}>
                    ×
                  </button>
                </span>
              ))}
          </div>
          <div className="flex gap-1.75">
            <input value={newPreset} onChange={(event) => setNewPreset(event.target.value)} placeholder={t('payments.newPreset')} aria-label={t('payments.newPreset')} className={`${controlClass()} min-w-0 flex-1 border-dashed`} />
            <button
              type="button"
              className={buttonClass('outline', 'sm')}
              disabled={newPreset.trim().length < 2 || addPreset.isPending}
              onClick={() => addPreset.mutate(undefined, { onSuccess: () => setNewPreset('') })}
            >
              {t('common.add')}
            </button>
          </div>
          {addPreset.error ? <ErrorNotice error={addPreset.error} /> : null}
        </div>

        <TextInput label={t('payments.description')} value={form.description} onChange={(description) => set({ description })} error={fieldError('description')} required />
        <div className="grid-auto-fit-half-140 grid gap-3">
          <NumberInput label={t('payments.amount')} value={form.amount} onChange={(amount) => set({ amount })} preview={(value) => bdt(value)} error={fieldError('amount')} />
          <TextInput label={t('payments.date')} type="date" max={todayInDhaka()} value={form.occurred_on} onChange={(occurred_on) => set({ occurred_on })} error={fieldError('occurred_on')} />
        </div>
        <TextInput label={t('bookings.paymentReference')} value={form.reference} onChange={(reference) => set({ reference })} hint={t('bookings.paymentReferenceHint')} />

        <EvidenceInput file={evidence} onChange={setEvidence} error={fieldError('evidence')} />

        {post.error && !(post.error instanceof ApiError && post.error.status === 422) ? <ErrorNotice error={post.error} /> : null}
        <button type="submit" className={buttonClass('cta', 'md', 'w-full')} disabled={!ready || post.isPending}>
          {post.isPending ? t('common.saving') : t('payments.post')}
        </button>
      </form>
    </Card>
  )
}
