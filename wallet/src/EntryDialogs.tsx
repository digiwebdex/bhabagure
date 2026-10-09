import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { DeleteIcon, EditIcon, EvidenceIcon, ViewIcon } from './icons'
import { ApiError } from './lib/api'
import { useDescribeChange, useKindLabel } from './lib/describe'
import { MAX_EVIDENCE_BYTES, openEvidence } from './lib/evidence'
import { todayInDhaka, useFormat } from './lib/format'
import { actions, useBookChange, useEntry, useSources, type Direction, type Entry } from './lib/queries'
import { Dialog, Field, IconButton, Notice, Pending, PrimaryButton, SecondaryButton, inputClass } from './ui'

/** The dialog open on an entry (docs/wallet-edits.md): View, Edit or Delete. */
export type EntryAction = { kind: 'view' | 'edit' | 'delete'; entry: Entry }

/** View, Edit or Delete; View moves on to Edit or Delete in place. `onDone` gets what to tell the person. */
export function EntryDialog({ action, onChange, onClose, onDone }: { action: EntryAction; onChange: (next: EntryAction) => void; onClose: () => void; onDone: (message: string) => void }) {
  if (action.kind === 'view') return <EntryView key={action.entry.id} entry={action.entry} onChange={onChange} onClose={onClose} />
  if (action.kind === 'edit') return <EntryEdit entry={action.entry} onClose={onClose} onDone={onDone} />
  return <EntryDelete entry={action.entry} onClose={onClose} onDone={onDone} />
}

/** View always; Edit and Delete while the entry stands (an edited or deleted version is history). */
export function EntryIcons({ entry, onOpen }: { entry: Entry; onOpen: (action: EntryAction) => void }) {
  const { t } = useTranslation()

  return (
    <span className="flex justify-end gap-1.5">
      <IconButton label={t('history.viewNamed', { reference: entry.reference })} tone="view" onClick={() => onOpen({ kind: 'view', entry })}>
        <ViewIcon />
      </IconButton>
      {entry.status === 'standing' ? (
        <>
          <IconButton label={t('history.editNamed', { reference: entry.reference })} onClick={() => onOpen({ kind: 'edit', entry })}>
            <EditIcon />
          </IconButton>
          <IconButton label={t('history.deleteNamed', { reference: entry.reference })} tone="out" onClick={() => onOpen({ kind: 'delete', entry })}>
            <DeleteIcon />
          </IconButton>
        </>
      ) : null}
    </span>
  )
}

function EntryView({ entry, onChange, onClose }: { entry: Entry; onChange: (next: EntryAction) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const { bdt, date, dateTime } = useFormat()
  const kindLabel = useKindLabel()
  const view = useEntry(entry.id)
  // The row's own data at once; the versions follow.
  const shown: Entry = view.data ?? entry
  const [evidenceFailed, setEvidenceFailed] = useState(false)
  // An edited version: the corrected one in its place, to open from here.
  const current = shown.replaced_by_id !== null ? view.data?.versions.find((version) => version.id === shown.replaced_by_id) : undefined
  const statusLine =
    shown.status === 'edited'
      ? t('entry.statusEdited', { when: dateTime(shown.changed_at ?? '') })
      : shown.status === 'deleted'
        ? t(shown.deal?.deleted ? 'entry.statusDeletedWithDeal' : 'entry.statusDeleted', { when: dateTime(shown.changed_at ?? '') })
        : null

  return (
    <Dialog title={t('entry.viewTitle', { reference: shown.reference })} onClose={onClose}>
      <div className="flex flex-col gap-1 rounded-14 border border-wallet-line bg-wallet-bg p-4">
        <span
          className={`font-display text-28 leading-1.2 font-extrabold ${shown.direction === 'in' ? 'text-wallet-in-soft' : 'text-wallet-out-soft'} ${shown.status === 'standing' ? '' : 'line-through opacity-70'}`}
          data-testid="entry-amount"
        >
          {shown.direction === 'in' ? '+' : '−'} {bdt(shown.amount)}
        </span>
        <span className="text-13 text-wallet-muted">{kindLabel(shown)}</span>
      </div>
      {statusLine ? (
        <Notice tone="violet">
          {statusLine}
          {shown.change_reason ? ` ${t('entry.reasonLine', { reason: shown.change_reason })}` : ''}
          {current ? (
            <button type="button" className="ml-1 cursor-pointer border-0 bg-transparent p-0 text-13 font-semibold text-wallet-link" onClick={() => onChange({ kind: 'view', entry: current })}>
              {t('entry.openNewer')}
            </button>
          ) : null}
        </Notice>
      ) : null}
      <dl className="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-13">
        <Detail label={t('entry.fields.reference')}>{shown.reference}</Detail>
        <Detail label={shown.deal ? t('entry.fields.deal') : t('entry.fields.source')}>{shown.source}</Detail>
        <Detail label={t('entry.fields.occurred_on')}>{date(shown.occurred_on)}</Detail>
        <Detail label={t('entry.recordedAt')}>{shown.created_at ? dateTime(shown.created_at) : '—'}</Detail>
        <Detail label={t('entry.fields.evidence')}>
          {shown.has_evidence ? (
            <button type="button" className="inline-flex cursor-pointer items-center gap-1 border-0 bg-transparent p-0 text-13 text-wallet-link" onClick={() => void openEvidence(shown.id).then((opened) => setEvidenceFailed(!opened))}>
              <EvidenceIcon /> {shown.evidence_name || t('history.evidence')}
            </button>
          ) : (
            <span className="text-wallet-faint">{t('entry.noEvidence')}</span>
          )}
        </Detail>
      </dl>
      {evidenceFailed ? <Notice tone="out">{t('history.evidenceFailed')}</Notice> : null}
      <section className="flex flex-col gap-2">
        <h3 className="m-0 text-14 font-semibold">{t('entry.historyTitle')}</h3>
        {view.data ? <Versions versions={view.data.versions} /> : <Pending error={view.error} />}
      </section>
      {shown.status === 'standing' ? (
        <div className="flex flex-wrap justify-end gap-2">
          <SecondaryButton onClick={() => onChange({ kind: 'edit', entry: shown })}>
            <EditIcon /> {t('entry.edit')}
          </SecondaryButton>
          <SecondaryButton tone="out" onClick={() => onChange({ kind: 'delete', entry: shown })}>
            <DeleteIcon /> {t('entry.delete')}
          </SecondaryButton>
        </div>
      ) : null}
    </Dialog>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-wallet-muted">{label}</dt>
      <dd className="m-0 min-w-0 break-words">{children}</dd>
    </>
  )
}

/** Every version, oldest first: as recorded, each edit with what it changed and why, and a delete. */
function Versions({ versions }: { versions: Entry[] }) {
  const { t } = useTranslation()
  const { bdt, date, dateTime } = useFormat()
  const describe = useDescribeChange()
  const when = (iso: string | null) => (iso ? dateTime(iso) : '')

  const steps: { key: string; title: string; lines: string[]; reason: string | null }[] = []
  versions.forEach((version, index) => {
    steps.push(
      index === 0
        ? {
            key: `recorded-${version.id}`,
            title: t('entry.steps.recorded', { when: when(version.created_at) }),
            lines: [`${version.direction === 'in' ? '+' : '−'} ${bdt(version.amount)} · ${version.source} · “${version.reference}” · ${date(version.occurred_on)}`],
            reason: null,
          }
        : { key: `edited-${version.id}`, title: t('entry.steps.edited', { when: when(version.created_at) }), lines: version.changes.map(describe), reason: versions[index - 1].change_reason },
    )
    if (version.status === 'deleted') {
      steps.push({ key: `deleted-${version.id}`, title: t(version.deal?.deleted ? 'entry.steps.deletedWithDeal' : 'entry.steps.deleted', { when: when(version.changed_at) }), lines: [], reason: version.change_reason })
    }
  })

  return (
    <ol className="m-0 flex list-none flex-col gap-2.5 border-l-2 border-wallet-line p-0 pl-3.5" data-testid="entry-versions">
      {steps.map((step) => (
        <li key={step.key} className="flex flex-col gap-0.5">
          <span className="text-13 font-semibold">{step.title}</span>
          {step.lines.map((line) => (
            <span key={line} className="text-12 text-wallet-muted">
              {line}
            </span>
          ))}
          {step.reason ? <span className="text-12 text-wallet-lilac">{t('entry.reasonLine', { reason: step.reason })}</span> : null}
        </li>
      ))}
    </ol>
  )
}

/**
 * Edit: the fields the entry was saved with. A deal's advance or payment keeps its deal (so no cash in/out or source);
 * evidence is kept, replaced or removed. Saving puts the corrected entry in its place.
 */
function EntryEdit({ entry, onClose, onDone }: { entry: Entry; onClose: () => void; onDone: (message: string) => void }) {
  const { t } = useTranslation()
  const { bdt } = useFormat()
  const sources = useSources()
  const isDealMoney = entry.deal !== null
  const [direction, setDirection] = useState<Direction>(entry.direction)
  const [amount, setAmount] = useState(String(entry.amount))
  const [sourceId, setSourceId] = useState(String(entry.source_id ?? ''))
  const [reference, setReference] = useState(entry.reference)
  const [occurredOn, setOccurredOn] = useState(entry.occurred_on)
  const [evidence, setEvidence] = useState<File | null>(null)
  const [removeEvidence, setRemoveEvidence] = useState(false)
  const [reason, setReason] = useState('')
  const save = useBookChange(actions.editEntry)
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)

  const value = Number(amount)
  const tooBig = evidence !== null && evidence.size > MAX_EVIDENCE_BYTES
  const changed =
    direction !== entry.direction ||
    value !== entry.amount ||
    (!isDealMoney && sourceId !== String(entry.source_id ?? '')) ||
    reference.trim() !== entry.reference ||
    occurredOn !== entry.occurred_on ||
    evidence !== null ||
    removeEvidence
  const ready = changed && value >= 1 && reference.trim().length >= 2 && occurredOn !== '' && !tooBig
  // Its own source stays a choice even if it was deleted from the list since.
  const sourceOptions = [...(sources.data ?? [])]
  if (entry.source_id !== null && !sourceOptions.some((source) => source.id === entry.source_id)) sourceOptions.push({ id: entry.source_id, name: entry.source })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!ready) return
    const body = new FormData()
    if (!isDealMoney) {
      body.append('direction', direction)
      body.append('source_id', sourceId)
    }
    body.append('amount', String(value))
    body.append('reference', reference.trim())
    body.append('occurred_on', occurredOn)
    if (evidence) body.append('evidence', evidence)
    else if (removeEvidence) body.append('remove_evidence', '1')
    if (reason.trim()) body.append('reason', reason.trim())
    save.mutate(
      { id: entry.id, form: body },
      {
        onSuccess: () => {
          onDone(t('entry.saved', { amount: bdt(value) }))
          onClose()
        },
      },
    )
  }

  return (
    <Dialog title={t('entry.editTitle', { reference: entry.reference })} onClose={onClose}>
      <form className="flex flex-col gap-3.5" onSubmit={submit} data-testid="entry-edit">
        {isDealMoney ? (
          <Notice tone="violet">{t('entry.dealMoneyNote', { name: entry.source })}</Notice>
        ) : (
          <div className="flex gap-2" role="group" aria-label={t('cash.kind')}>
            {(['in', 'out'] as const).map((kind) => (
              <button
                key={kind}
                type="button"
                aria-pressed={direction === kind}
                onClick={() => setDirection(kind)}
                className={`flex-1 cursor-pointer rounded-12 border px-3 py-2 text-13 font-bold ${
                  direction === kind ? (kind === 'in' ? 'border-wallet-in bg-wallet-in/20 text-wallet-in-soft' : 'border-wallet-out bg-wallet-out/20 text-wallet-out-soft') : 'border-wallet-input bg-transparent text-wallet-muted'
                }`}
              >
                {t(`cash.kinds.${kind}`)}
              </button>
            ))}
          </div>
        )}
        <Field label={t('cash.amount')} error={fieldError('amount')} hint={isDealMoney ? t('entry.dealMoneyLimit') : undefined}>
          <input className={`${inputClass} font-display text-18`} type="number" min={1} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
        </Field>
        {isDealMoney ? null : (
          <Field label={direction === 'in' ? t('cash.sourceIn') : t('cash.sourceOut')} error={fieldError('source_id')}>
            <select className={inputClass} value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
              {sourceOptions.map((source) => (
                <option key={source.id} value={source.id}>
                  {source.name}
                </option>
              ))}
            </select>
          </Field>
        )}
        <Field label={direction === 'in' ? t('cash.referenceIn') : t('cash.referenceOut')} error={fieldError('reference')}>
          <input className={inputClass} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={300} />
        </Field>
        <Field label={t('cash.date')} error={fieldError('occurred_on')}>
          <input className={inputClass} type="date" max={todayInDhaka()} value={occurredOn} onChange={(e) => setOccurredOn(e.target.value)} />
        </Field>
        <EvidenceChoice
          entry={entry}
          file={evidence}
          removed={removeEvidence}
          tooBig={tooBig}
          onFile={(file) => {
            setEvidence(file)
            setRemoveEvidence(false)
          }}
          onRemoved={setRemoveEvidence}
        />
        <Field label={t('entry.reasonOptional')}>
          <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} placeholder={t('entry.reasonPlaceholder')} />
        </Field>
        {save.error && !(save.error instanceof ApiError && save.error.status === 422 && !save.error.code) ? <Notice tone="out">{save.error.message}</Notice> : null}
        <p className="m-0 text-12 leading-1.55 text-wallet-muted">{t('entry.editNote')}</p>
        <div className="flex flex-wrap justify-end gap-2">
          <SecondaryButton onClick={onClose}>{t('history.cancel')}</SecondaryButton>
          <PrimaryButton type="submit" disabled={!ready || save.isPending}>
            {t('entry.save')}
          </PrimaryButton>
        </div>
      </form>
    </Dialog>
  )
}

/** Evidence on an edit: keep the file it has, attach a new one in its place, or remove it. */
function EvidenceChoice({ entry, file, removed, tooBig, onFile, onRemoved }: { entry: Entry; file: File | null; removed: boolean; tooBig: boolean; onFile: (file: File | null) => void; onRemoved: (removed: boolean) => void }) {
  const { t } = useTranslation()
  const small = 'cursor-pointer rounded-8 border border-wallet-input bg-transparent px-2.5 py-1 text-12 font-semibold'

  return (
    <div className="flex flex-col gap-2 rounded-12 border border-dashed border-wallet-input bg-wallet-bg p-3 text-13">
      <span className="text-wallet-muted">{t('cash.evidence')}</span>
      {file ? (
        <span className="flex items-center justify-between gap-2">
          <span className="min-w-0 truncate font-semibold">{file.name}</span>
          <button type="button" className={`${small} text-wallet-muted`} onClick={() => onFile(null)}>
            {t('entry.evidenceUndo')}
          </button>
        </span>
      ) : entry.has_evidence && !removed ? (
        <span className="flex flex-wrap items-center justify-between gap-2">
          <button type="button" className="inline-flex min-w-0 cursor-pointer items-center gap-1 border-0 bg-transparent p-0 text-13 text-wallet-link" onClick={() => void openEvidence(entry.id)}>
            <EvidenceIcon /> <span className="truncate">{entry.evidence_name || t('history.evidence')}</span>
          </button>
          <button type="button" className={`${small} text-wallet-out-soft`} onClick={() => onRemoved(true)}>
            {t('entry.evidenceRemove')}
          </button>
        </span>
      ) : removed ? (
        <span className="flex items-center justify-between gap-2 text-wallet-out-soft">
          {t('entry.evidenceWillGo')}
          <button type="button" className={`${small} text-wallet-muted`} onClick={() => onRemoved(false)}>
            {t('entry.evidenceUndo')}
          </button>
        </span>
      ) : (
        <span className="text-wallet-faint">{t('entry.noEvidence')}</span>
      )}
      <label className={`${small} self-start text-wallet-lilac`}>
        {entry.has_evidence || file ? t('entry.evidenceReplace') : t('entry.evidenceAttach')}
        <input type="file" accept="image/*,.pdf" className="sr-only" onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
      </label>
      <span className={`text-12 ${tooBig ? 'text-wallet-out-soft' : 'text-wallet-faint'}`}>{tooBig ? t('cash.evidenceTooBig') : t('entry.evidenceKeepNote')}</span>
    </div>
  )
}

/** Delete, with an optional reason: the entry leaves the balance and the list, and the history keeps it. */
function EntryDelete({ entry, onClose, onDone }: { entry: Entry; onClose: () => void; onDone: (message: string) => void }) {
  const { t } = useTranslation()
  const { bdt, date } = useFormat()
  const [reason, setReason] = useState('')
  const remove = useBookChange(actions.deleteEntry)

  return (
    <Dialog title={t('entry.deleteTitle', { amount: bdt(entry.amount) })} onClose={onClose}>
      <p className="m-0 text-14 leading-1.55">{t('entry.deleteQuestion', { reference: entry.reference, date: date(entry.occurred_on) })}</p>
      <p className="m-0 text-13 leading-1.55 text-wallet-muted">{entry.deal ? t('entry.deleteDealMoneyNote', { name: entry.source }) : t('entry.deleteNote')}</p>
      <Field label={t('entry.reasonOptional')}>
        <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} placeholder={t('entry.reasonPlaceholder')} autoFocus />
      </Field>
      {remove.error ? <Notice tone="out">{remove.error.message}</Notice> : null}
      <div className="flex flex-wrap justify-end gap-2">
        <SecondaryButton onClick={onClose}>{t('history.cancel')}</SecondaryButton>
        <PrimaryButton
          tone="out"
          className="inline-flex items-center gap-1.5"
          disabled={remove.isPending}
          onClick={() =>
            remove.mutate(
              { id: entry.id, reason: reason.trim() },
              {
                onSuccess: () => {
                  onDone(t('entry.deleted', { amount: bdt(entry.amount) }))
                  onClose()
                },
              },
            )
          }
        >
          <DeleteIcon /> {t('entry.delete')}
        </PrimaryButton>
      </div>
    </Dialog>
  )
}
