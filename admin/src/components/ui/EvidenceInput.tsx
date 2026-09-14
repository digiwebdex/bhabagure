import { useId, useRef } from 'react'
import { useTranslation } from 'react-i18next'

/** 5 MB, as the API allows (EvidenceStore::MAX_KB). */
export const EVIDENCE_MAX_BYTES = 5 * 1024 * 1024

/**
 * The receipt, bank slip or screenshot that every staff-recorded money movement carries (decided 2026-09-14): a payment
 * on a booking, a manual cash entry, a deal payment or advance. Image or PDF, 5 MB, stored privately by the API.
 * A file over the limit is refused here, before anything is sent.
 */
export function EvidenceInput({ file, onChange, error, required = true }: { file: File | null; onChange: (file: File | null) => void; error?: string; required?: boolean }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const noteId = useId()
  const tooBig = file !== null && file.size > EVIDENCE_MAX_BYTES
  const message = tooBig ? t('evidence.tooBig') : error

  return (
    <div className="flex flex-col gap-1.25">
      <label className={`flex cursor-pointer items-center gap-2.75 rounded-11 border border-dashed px-3.25 py-3 hover:border-blue ${message ? 'border-red' : file ? 'border-green bg-green-tint/50' : 'border-app-line'}`}>
        <span className={`flex size-8 shrink-0 items-center justify-center rounded-9 text-14 text-white ${file && !tooBig ? 'bg-green' : 'bg-app-muted'}`} aria-hidden>
          ⎘
        </span>
        <span className="flex min-w-0 flex-1 flex-col leading-1.3">
          <span className="truncate text-13 font-semibold">{file ? file.name : t('evidence.attach')}</span>
          <span className="truncate text-11 text-app-muted">{required ? t('evidence.requiredHint') : t('evidence.hint')}</span>
        </span>
        {file ? (
          <button
            type="button"
            className="size-6.5 shrink-0 cursor-pointer rounded-7 border-0 bg-app-surface-2 text-13 text-amber"
            aria-label={t('evidence.remove')}
            onClick={(event) => {
              event.preventDefault()
              onChange(null)
              if (input.current) input.current.value = ''
            }}
          >
            ×
          </button>
        ) : null}
        <input
          ref={input}
          type="file"
          accept="image/*,.pdf"
          className="sr-only"
          aria-label={t('evidence.attach')}
          aria-required={required}
          aria-invalid={!!message}
          aria-describedby={message ? noteId : undefined}
          onChange={(event) => onChange(event.target.files?.[0] ?? null)}
        />
      </label>
      {message ? (
        <span id={noteId} role="alert" className="text-12 font-semibold text-red">
          {message}
        </span>
      ) : null}
    </div>
  )
}

/** Ready to send: present (when required) and within the limit. */
// eslint-disable-next-line react-refresh/only-export-components -- the check belongs with the input it validates
export const evidenceReady = (file: File | null, required = true) => (file === null ? !required : file.size <= EVIDENCE_MAX_BYTES)
