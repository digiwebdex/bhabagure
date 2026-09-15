import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError } from './lib/api'
import { todayInDhaka, useFormat } from './lib/format'
import { actions, useBookChange, usePresets, useSources, type Direction } from './lib/queries'
import { Card, Chip, Field, Notice, PrimaryButton, inputClass } from './ui'

const MAX_EVIDENCE_BYTES = 5 * 1024 * 1024

/**
 * Cash in or out (the prototype's left column): amount, source, a saved or typed reference, the date and evidence. A
 * reference is required — an entry without a reason can't be saved. Sources and saved references are edited here too.
 */
export function CashForm() {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const sources = useSources()
  const presets = usePresets()
  const [direction, setDirection] = useState<Direction>('in')
  const [amount, setAmount] = useState('')
  const [sourceId, setSourceId] = useState('')
  const [reference, setReference] = useState('')
  const [occurredOn, setOccurredOn] = useState(todayInDhaka())
  const [evidence, setEvidence] = useState<File | null>(null)
  const [newPreset, setNewPreset] = useState('')
  const [newSource, setNewSource] = useState('')
  const [done, setDone] = useState<string | null>(null)
  const record = useBookChange(actions.record)
  const addPreset = useBookChange(actions.addPreset)
  const removePreset = useBookChange(actions.removePreset)
  const addSource = useBookChange(actions.addSource)
  const fieldError = (name: string) => (record.error instanceof ApiError ? record.error.field(name) : undefined)

  const isIn = direction === 'in'
  const chosenSource = sourceId || String(sources.data?.[0]?.id ?? '')
  const value = Number(amount)
  const tooBig = evidence !== null && evidence.size > MAX_EVIDENCE_BYTES
  const ready = value >= 1 && reference.trim().length >= 2 && chosenSource !== '' && !tooBig

  return (
    <Card>
      <div className="flex gap-2" role="group" aria-label={t('cash.kind')}>
        {(['in', 'out'] as const).map((kind) => (
          <button
            key={kind}
            type="button"
            aria-pressed={direction === kind}
            onClick={() => {
              setDirection(kind)
              setReference('')
              setDone(null)
            }}
            className={`flex-1 cursor-pointer rounded-12 border px-3 py-2.5 text-14 font-bold ${
              direction === kind ? (kind === 'in' ? 'border-wallet-in bg-wallet-in/20 text-wallet-in-soft' : 'border-wallet-out bg-wallet-out/20 text-wallet-out-soft') : 'border-wallet-input bg-transparent text-wallet-muted'
            }`}
          >
            {t(`cash.kinds.${kind}`)}
          </button>
        ))}
      </div>

      <form
        className="flex flex-col gap-3.5"
        data-testid="cash-form"
        onSubmit={(event) => {
          event.preventDefault()
          if (!ready) return
          const body = new FormData()
          body.append('direction', direction)
          body.append('amount', String(value))
          body.append('source_id', chosenSource)
          body.append('reference', reference.trim())
          body.append('occurred_on', occurredOn)
          if (evidence) body.append('evidence', evidence)
          record.mutate(body, {
            onSuccess: () => {
              setDone(t(isIn ? 'cash.recordedIn' : 'cash.recordedOut', { amount: bdt(value) }))
              setAmount('')
              setReference('')
              setEvidence(null)
            },
          })
        }}
      >
        <Field label={t('cash.amount')} error={fieldError('amount')}>
          <input className={`${inputClass} font-display text-20`} type="number" min={1} inputMode="decimal" placeholder="0" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </Field>

        <Field label={isIn ? t('cash.sourceIn') : t('cash.sourceOut')} error={fieldError('source_id')}>
          <select className={inputClass} value={chosenSource} onChange={(e) => setSourceId(e.target.value)} disabled={!sources.data}>
            {!sources.data ? <option value="">{sources.error ? t('errors.network') : t('loading')}</option> : null}
            {(sources.data ?? []).map((source) => (
              <option key={source.id} value={source.id}>
                {source.name}
              </option>
            ))}
          </select>
        </Field>
        <details className="text-12 text-wallet-muted">
          <summary className="cursor-pointer">{t('cash.manageSources')}</summary>
          <div className="mt-2 flex gap-2">
            <input className={`${inputClass} py-2 text-13`} value={newSource} onChange={(e) => setNewSource(e.target.value)} placeholder={t('cash.newSource')} aria-label={t('cash.newSource')} />
            <button
              type="button"
              className="cursor-pointer rounded-10 border border-wallet-input bg-transparent px-3 text-13 font-semibold text-wallet-lilac disabled:opacity-50"
              disabled={newSource.trim().length < 2 || addSource.isPending}
              onClick={() => addSource.mutate(newSource.trim(), { onSuccess: () => setNewSource('') })}
            >
              {t('cash.add')}
            </button>
          </div>
          {addSource.error ? <p className="m-0 mt-1 text-wallet-out-soft">{addSource.error.message}</p> : null}
        </details>

        <div className="flex flex-col gap-2">
          <span className="text-13 text-wallet-muted">{isIn ? t('cash.presetsIn') : t('cash.presetsOut')}</span>
          <div className="flex flex-wrap gap-1.5" data-testid="presets">
            {(presets.data ?? [])
              .filter((preset) => preset.direction === direction)
              .map((preset) => (
                <span key={preset.id} className="flex items-center">
                  <Chip on={reference === preset.label} onClick={() => setReference(preset.label)}>
                    {preset.label}
                  </Chip>
                  <button
                    type="button"
                    className="-ml-1 cursor-pointer border-0 bg-transparent px-1.5 text-14 text-wallet-faint"
                    aria-label={t('cash.removePreset', { label: preset.label })}
                    onClick={() => removePreset.mutate(preset.id)}
                  >
                    ×
                  </button>
                </span>
              ))}
          </div>
          <div className="flex gap-2">
            <input className={`${inputClass} border-dashed py-2 text-13`} value={newPreset} onChange={(e) => setNewPreset(e.target.value)} placeholder={t('cash.newPreset')} aria-label={t('cash.newPreset')} />
            <button
              type="button"
              className="cursor-pointer rounded-10 border border-wallet-input bg-transparent px-3 text-13 font-semibold text-wallet-lilac disabled:opacity-50"
              disabled={newPreset.trim().length < 2 || addPreset.isPending}
              onClick={() =>
                addPreset.mutate(
                  { direction, label: newPreset.trim() },
                  {
                    onSuccess: () => {
                      setReference(newPreset.trim())
                      setNewPreset('')
                    },
                  },
                )
              }
            >
              {t('cash.save')}
            </button>
          </div>
        </div>

        <Field label={isIn ? t('cash.referenceIn') : t('cash.referenceOut')} error={fieldError('reference')}>
          <input className={inputClass} value={reference} onChange={(e) => setReference(e.target.value)} placeholder={isIn ? t('cash.referenceInPlaceholder') : t('cash.referenceOutPlaceholder')} maxLength={300} />
        </Field>

        <Field label={t('cash.date')} error={fieldError('occurred_on')}>
          <input className={inputClass} type="date" max={todayInDhaka()} value={occurredOn} onChange={(e) => setOccurredOn(e.target.value)} />
        </Field>

        <label className={`flex cursor-pointer items-center gap-3 rounded-12 border border-dashed p-3 ${evidence ? 'border-wallet-in-soft/45 bg-wallet-in/10' : 'border-wallet-input bg-wallet-bg'}`}>
          <span aria-hidden className={`flex size-9 shrink-0 items-center justify-center rounded-10 text-16 text-white ${evidence ? 'bg-wallet-in-deep' : 'bg-wallet-plum'}`}>
            {evidence ? '✓' : '⎙'}
          </span>
          <span className="flex min-w-0 flex-1 flex-col">
            <span className="truncate text-13 font-semibold">{evidence ? evidence.name : t('cash.evidence')}</span>
            <span className={`text-12 ${tooBig ? 'text-wallet-out-soft' : 'text-wallet-muted'}`}>{tooBig ? t('cash.evidenceTooBig') : evidence ? t('cash.evidenceChosen') : t('cash.evidenceNote')}</span>
          </span>
          {evidence ? (
            <button type="button" className="cursor-pointer border-0 bg-transparent text-18 text-wallet-muted" aria-label={t('cash.evidenceRemove')} onClick={(e) => { e.preventDefault(); setEvidence(null) }}>
              ×
            </button>
          ) : null}
          <input type="file" accept="image/*,.pdf" className="sr-only" aria-label={t('cash.evidence')} onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
        </label>

        {record.error && !(record.error instanceof ApiError && record.error.status === 422 && !record.error.code) ? <Notice tone="out">{record.error.message}</Notice> : null}
        {done ? <Notice tone="in">{done}</Notice> : null}
        <PrimaryButton type="submit" tone={isIn ? 'in' : 'out'} disabled={!ready || record.isPending}>
          {isIn ? t('cash.submitIn') : t('cash.submitOut')}
        </PrimaryButton>
      </form>
    </Card>
  )
}
