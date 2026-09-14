import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { buttonClass } from '../../components/ui/button'
import { Dialog, ErrorNotice, useToast } from '../../components/ui/feedback'
import { Pair, SelectInput, TextArea, TextInput } from '../../components/ui/fields'
import { ApiError } from '../../lib/api/client'
import { documentActions, documentName, DOCUMENT_TYPES, useDocumentAction, useDocumentOwners, type DocumentType, type StaffDocument } from './api'

/** Matches StaffDocuments::MAX_KB in the API. */
const MAX_BYTES = 5 * 1024 * 1024

/**
 * Upload a staff document — for a given person (their record), for someone picked from the staff (the Vault), or as the
 * replacement of an existing document, which the API archives as replaced.
 */
export function UploadDocumentDialog({ staffId, replacing, onClose }: { staffId?: number; replacing?: StaffDocument; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const owners = useDocumentOwners(staffId === undefined && replacing === undefined)
  const [owner, setOwner] = useState('')
  const [form, setForm] = useState({
    type: (replacing?.type ?? 'passport') as DocumentType,
    title: replacing?.title ?? '',
    number: replacing?.number ?? '',
    issued_on: '',
    expires_on: '',
    note: '',
  })
  const [file, setFile] = useState<File | null>(null)
  const ownerId = replacing?.staff.id ?? staffId ?? Number(owner)
  const save = useDocumentAction((fields: typeof form & { file: File }) => (replacing ? documentActions.replace(replacing.id)(fields) : documentActions.upload(ownerId)(fields)))
  const fieldError = (name: string) => (save.error instanceof ApiError ? save.error.field(name) : undefined)
  const needsTitle = form.type === 'certificate' || form.type === 'other'
  const tooBig = file !== null && file.size > MAX_BYTES
  const ready = ownerId > 0 && file !== null && !tooBig && (!needsTitle || form.title.trim() !== '')

  return (
    <Dialog open onClose={onClose} title={replacing ? t('vault.replaceTitle', { document: documentName(t, replacing) }) : t('vault.uploadTitle')}>
      {staffId === undefined && replacing === undefined ? (
        <SelectInput
          label={t('vault.owner')}
          value={owner}
          onChange={setOwner}
          options={[{ value: '', label: owners.isPending ? t('common.working') : t('vault.pickOwner') }, ...(owners.data?.data ?? []).map((person) => ({ value: String(person.id), label: `${person.name} · ${person.employee_code}` }))]}
        />
      ) : null}
      <Pair>
        <SelectInput label={t('vault.type')} value={form.type} onChange={(type) => setForm({ ...form, type: type as DocumentType })} options={DOCUMENT_TYPES.map((value) => ({ value, label: t(`vault.types.${value}`) }))} error={fieldError('type')} />
        <TextInput label={t('vault.titleField')} value={form.title} onChange={(title) => setForm({ ...form, title })} error={fieldError('title')} hint={needsTitle ? t('vault.titleNeeded') : undefined} maxLength={120} />
      </Pair>
      <Pair>
        <TextInput label={t('vault.number')} value={form.number} onChange={(number) => setForm({ ...form, number })} error={fieldError('number')} maxLength={40} autoComplete="off" />
        <TextInput label={t('vault.issuedOn')} type="date" value={form.issued_on} onChange={(issued_on) => setForm({ ...form, issued_on })} error={fieldError('issued_on')} />
      </Pair>
      <Pair>
        <TextInput label={t('vault.expiresOn')} type="date" value={form.expires_on} onChange={(expires_on) => setForm({ ...form, expires_on })} error={fieldError('expires_on')} hint={t('vault.expiresHint')} />
        <TextInput label={t('vault.note')} value={form.note} onChange={(note) => setForm({ ...form, note })} error={fieldError('note')} maxLength={300} />
      </Pair>
      <label className="flex flex-col gap-1.25 text-13">
        <span className="text-app-muted">{t('vault.file')}</span>
        <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        <span className={`text-12 ${tooBig || fieldError('file') ? 'font-semibold text-red' : 'text-app-muted'}`}>{tooBig ? t('vault.tooBig') : (fieldError('file') ?? t('vault.fileHint'))}</span>
      </label>
      {save.error && !(save.error instanceof ApiError && save.error.status === 422) ? <ErrorNotice error={save.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('primary')}
          disabled={!ready || save.isPending}
          onClick={() => file && save.mutate({ ...form, file }, { onSuccess: () => { toast(replacing ? t('vault.replaced') : t('vault.uploaded')); onClose() } })}
        >
          {save.isPending ? t('common.working') : replacing ? t('vault.replace') : t('vault.upload')}
        </button>
      </div>
    </Dialog>
  )
}

export function ArchiveDocumentDialog({ document, onClose }: { document: StaffDocument; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const archive = useDocumentAction(() => documentActions.archive(document.id)(reason.trim()))

  return (
    <Dialog open onClose={onClose} title={t('vault.archiveTitle', { document: documentName(t, document) })}>
      <p className="m-0 text-13 leading-1.6 text-app-muted">{t('vault.archiveNote')}</p>
      <TextArea label={t('vault.archiveReason')} value={reason} onChange={setReason} rows={3} maxLength={300} />
      {archive.error ? <ErrorNotice error={archive.error} /> : null}
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={onClose}>
          {t('common.cancel')}
        </button>
        <button
          type="button"
          className={buttonClass('danger')}
          disabled={reason.trim().length < 3 || archive.isPending}
          onClick={() => archive.mutate(undefined, { onSuccess: () => { toast(t('vault.archivedToast')); onClose() } })}
        >
          {t('vault.archive')}
        </button>
      </div>
    </Dialog>
  )
}
