import { useEffect, useRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { CloseIcon } from './icons'

/** The prototype's frame: the bar with the wordmark and the isolation badge, the page, and the footer note. */
export function Shell({ children, actions }: { children?: ReactNode; actions?: ReactNode }) {
  const { t } = useTranslation()

  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-wallet-line bg-wallet-bar">
        <div className="mx-auto flex max-w-[1160px] flex-wrap items-center justify-between gap-3.5 px-[clamp(16px,4vw,24px)] py-4">
          <span className="flex min-w-0 flex-col leading-1.7">
            <strong className="text-16 font-bold">{t('title')}</strong>
            <span className="font-display text-10 font-bold tracking-[0.16em] text-wallet-violet uppercase">Private cash account</span>
          </span>
          <div className="flex flex-wrap items-center gap-2.5">
            <span className="flex items-center gap-2 rounded-pill border border-wallet-violet/40 bg-wallet-purple-deep/30 px-3 py-1.75 text-12 font-semibold whitespace-nowrap text-wallet-lilac">
              <span aria-hidden className="size-1.75 rounded-full bg-wallet-violet" />
              {t('isolationBadge')}
            </span>
            {actions}
          </div>
        </div>
      </header>
      <main className="mx-auto flex w-full max-w-[1160px] flex-1 flex-col gap-5 px-[clamp(16px,4vw,24px)] py-[clamp(18px,4vw,28px)]">{children}</main>
      <footer className="border-t border-wallet-line bg-wallet-bar">
        <div className="mx-auto max-w-[1160px] px-[clamp(16px,4vw,24px)] py-3.5 text-12 text-wallet-faint">{t('footNote')}</div>
      </footer>
    </div>
  )
}

/** Loading, or the error that stopped it: never an empty list while the data is still on its way. */
export function Pending({ error }: { error?: unknown }) {
  const { t } = useTranslation()
  if (error) return <Notice tone="out">{error instanceof Error ? error.message : t('errors.network')}</Notice>
  return (
    <p role="status" className="m-0 text-13 text-wallet-muted">
      {t('loading')}
    </p>
  )
}

export const inputClass =
  'w-full min-w-0 rounded-10 border border-wallet-input bg-wallet-bg px-3 py-2.5 text-14 text-wallet-text outline-none focus:border-wallet-violet placeholder:text-wallet-faint'

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`flex flex-col gap-3.5 rounded-18 border border-wallet-line bg-wallet-surface p-5 ${className}`}>{children}</section>
}

export function Field({ label, children, error, hint }: { label: string; children: ReactNode; error?: string; hint?: string }) {
  return (
    <label className="flex flex-col gap-1.5 text-13 text-wallet-muted">
      {label}
      {children}
      {error ? <span className="text-12 text-wallet-out-soft">{error}</span> : hint ? <span className="text-12 text-wallet-faint">{hint}</span> : null}
    </label>
  )
}

export function Notice({ tone, children }: { tone: 'in' | 'out' | 'violet'; children: ReactNode }) {
  const tones = {
    in: 'border-wallet-in-soft/45 bg-wallet-in/10 text-wallet-in-soft',
    out: 'border-wallet-out-soft/45 bg-wallet-out/10 text-wallet-out-soft',
    violet: 'border-wallet-violet/30 bg-wallet-purple-deep/15 text-wallet-lilac',
  }
  return (
    <div role={tone === 'out' ? 'alert' : 'note'} className={`rounded-12 border px-3.5 py-2.5 text-13 leading-1.55 ${tones[tone]}`}>
      {children}
    </div>
  )
}

export function PrimaryButton({ tone = 'violet', className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { tone?: 'violet' | 'in' | 'out' }) {
  const tones = {
    violet: 'bg-gradient-to-br from-wallet-purple-deep to-wallet-plum',
    in: 'bg-gradient-to-br from-wallet-in-deep to-green-deep',
    out: 'bg-gradient-to-br from-wallet-out-deep to-red',
  }
  return (
    <button
      {...props}
      className={`cursor-pointer rounded-12 border-0 px-4 py-3 text-14 font-bold text-white disabled:cursor-not-allowed disabled:opacity-55 ${tones[tone]} ${className}`}
    />
  )
}

export function SecondaryButton({ tone = 'plain', className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { tone?: 'plain' | 'out' }) {
  return (
    <button
      type="button"
      {...props}
      className={`inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-12 border bg-transparent px-4 py-2.5 text-14 font-semibold disabled:cursor-not-allowed disabled:opacity-55 ${tone === 'out' ? 'border-wallet-out-soft/50 text-wallet-out-soft' : 'border-wallet-input text-wallet-text'} ${className}`}
    />
  )
}

/**
 * An icon action (docs/wallet-edits.md): View, Edit, Delete or Add payment. The action's name is the button's name for
 * screen readers and its tooltip.
 */
export function IconButton({ label, tone = 'plain', className = '', ...props }: Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'aria-label'> & { label: string; tone?: 'plain' | 'view' | 'in' | 'out' }) {
  const tones = {
    plain: 'text-wallet-muted hover:text-wallet-text',
    view: 'text-wallet-lilac hover:text-wallet-lavender',
    in: 'text-wallet-in-soft',
    out: 'text-wallet-out-soft',
  }
  return (
    <button
      type="button"
      title={label}
      {...props}
      aria-label={label}
      className={`inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-8 border border-wallet-input bg-transparent hover:border-wallet-violet disabled:cursor-not-allowed disabled:opacity-40 ${tones[tone]} ${className}`}
    />
  )
}

/** A dialog over the page. Escape, ×, or a click that starts and ends on the backdrop closes it. */
export function Dialog({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const { t } = useTranslation()
  const pressedBackdrop = useRef(false)

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={title}
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-3 sm:items-center sm:p-4"
      onMouseDown={(event) => {
        pressedBackdrop.current = event.target === event.currentTarget
      }}
      onClick={(event) => {
        if (pressedBackdrop.current && event.target === event.currentTarget) onClose()
      }}
    >
      <div className="flex max-h-[92vh] w-full max-w-lg flex-col gap-3.5 overflow-y-auto rounded-18 border border-wallet-line bg-wallet-surface p-5">
        <div className="flex items-start justify-between gap-3">
          <h2 className="m-0 text-17 leading-1.4 font-semibold">{title}</h2>
          <IconButton label={t('close')} onClick={onClose}>
            <CloseIcon />
          </IconButton>
        </div>
        {children}
      </div>
    </div>
  )
}

export function Chip({ on, onClick, children, label }: { on: boolean; onClick: () => void; children: ReactNode; label?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={on}
      aria-label={label}
      className={`cursor-pointer rounded-pill border px-3 py-1.5 text-12 font-semibold ${on ? 'border-wallet-violet bg-wallet-purple-deep/40 text-wallet-lavender' : 'border-wallet-input bg-transparent text-wallet-muted'}`}
    >
      {children}
    </button>
  )
}
