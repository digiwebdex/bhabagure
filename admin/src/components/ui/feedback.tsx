import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { ApiError, NetworkError } from '../../lib/api/client'
import { buttonClass } from './button'

/** Red notice for a failed request; lists the publish checklist when the API sent one. */
export function ErrorNotice({ error }: { error: unknown }) {
  const { t } = useTranslation()
  if (!error) return null

  const message =
    error instanceof ApiError
      ? error.problems.length > 0 || Object.keys(error.errors).length > 0 || error.status < 500
        ? error.message
        : t('errors.server')
      : error instanceof NetworkError
        ? t('errors.network')
        : t('errors.unknown')

  return (
    <div role="alert" className="flex flex-col gap-1.5 rounded-11 border border-red-line bg-red-tint px-3.5 py-3 text-13 leading-1.5 font-semibold text-red">
      {message}
      {error instanceof ApiError && error.problems.length > 0 ? (
        <ul className="m-0 flex list-disc flex-col gap-0.5 pl-5 font-normal">
          {error.problems.map((problem) => (
            <li key={problem}>{problem}</li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

type Toast = { id: number; text: string; tone: 'success' | 'error' }
const ToastContext = createContext<(text: string, tone?: Toast['tone']) => void>(() => {})

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const next = useRef(1)

  const push = useCallback((text: string, tone: Toast['tone'] = 'success') => {
    const id = next.current++
    setToasts((current) => [...current, { id, text, tone }])
    window.setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 4000)
  }, [])

  return (
    <ToastContext.Provider value={push}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-80 flex flex-col items-end gap-2">
        {toasts.map((toast) => (
          <div key={toast.id} className={`rounded-11 px-4 py-2.5 text-13 font-semibold text-white shadow-raised ${toast.tone === 'success' ? 'bg-green-deep' : 'bg-red'}`}>
            {toast.text}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components -- the hook belongs with its provider
export const useToast = () => useContext(ToastContext)

/**
 * Modal on the native <dialog>: focus trap, Escape and inert background come from the browser.
 * Closes on backdrop click.
 */
export function Dialog({ open, onClose, title, children, wide = false }: { open: boolean; onClose: () => void; title: string; children: ReactNode; wide?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null)
  const { t } = useTranslation()

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={ref}
      onClose={onClose}
      onClick={(event) => event.target === ref.current && onClose()}
      aria-label={title}
      className={`m-auto max-h-dialog-h w-dialog-w overflow-hidden rounded-16 border border-app-line bg-app-surface p-0 text-app-text shadow-modal backdrop:bg-scrim/55 ${wide ? 'max-w-modal-lg' : 'max-w-form'}`}
    >
      {open ? (
        <div className="flex max-h-dialog-h flex-col">
          <div className="flex items-center justify-between gap-3 border-b border-app-line px-5 py-3.5">
            <h2 className="m-0 text-17 font-semibold">{title}</h2>
            <button type="button" onClick={onClose} aria-label={t('common.close')} className="size-8 cursor-pointer rounded-8 bg-app-surface-2 text-16 text-app-text">
              ×
            </button>
          </div>
          <div className="flex flex-col gap-4 overflow-y-auto p-5">{children}</div>
        </div>
      ) : null}
    </dialog>
  )
}

/** Confirmation for destructive actions. Resolves true when confirmed. */
// eslint-disable-next-line react-refresh/only-export-components -- returns the dialog element it controls
export function useConfirm() {
  const { t } = useTranslation()
  const [state, setState] = useState<{ text: string; resolve: (ok: boolean) => void } | null>(null)

  const confirm = useCallback((text: string) => new Promise<boolean>((resolve) => setState({ text, resolve })), [])
  const finish = (ok: boolean) => {
    state?.resolve(ok)
    setState(null)
  }

  const element = (
    <Dialog open={!!state} onClose={() => finish(false)} title={t('common.confirmTitle')}>
      <p className="m-0 text-14 leading-1.6">{state?.text}</p>
      <div className="flex justify-end gap-2">
        <button type="button" className={buttonClass('outline')} onClick={() => finish(false)}>
          {t('common.cancel')}
        </button>
        <button type="button" className={buttonClass('cta')} onClick={() => finish(true)}>
          {t('common.confirm')}
        </button>
      </div>
    </Dialog>
  )

  return { confirm, element }
}

/** Shown when leaving an editor with unsaved changes (react-router useBlocker). */
export function UnsavedChangesPrompt({ onStay, onLeave }: { onStay: () => void; onLeave: () => void }) {
  const { t } = useTranslation()
  return (
    <div role="alertdialog" aria-live="assertive" className="fixed inset-x-4 bottom-4 z-70 mx-auto flex max-w-form flex-wrap items-center justify-between gap-3 rounded-14 border border-app-line bg-app-surface px-4 py-3 shadow-modal">
      <span className="text-14 font-medium">{t('common.unsavedChanges')}</span>
      <span className="flex gap-2">
        <button type="button" className={buttonClass('outline', 'sm')} onClick={onLeave}>
          {t('common.discard')}
        </button>
        <button type="button" className={buttonClass('primary', 'sm')} onClick={onStay}>
          {t('common.keepEditing')}
        </button>
      </span>
    </div>
  )
}
