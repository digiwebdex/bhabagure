import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

/** White card with the prototype's 16px radius and hairline border. */
export function Card({ children, className = '', padded = true }: { children: ReactNode; className?: string; padded?: boolean }) {
  return (
    <section className={`flex min-w-0 flex-col gap-3.5 rounded-16 border border-app-line bg-app-surface ${padded ? 'p-4.5' : ''} ${className}`}>
      {children}
    </section>
  )
}

/**
 * Bilingual card heading as in the prototype: in Bangla the English name follows in muted display type;
 * in English only the English name shows.
 */
export function CardTitle({ bn, en, aside, as: Tag = 'h2' }: { bn: string; en: string; aside?: ReactNode; as?: 'h2' | 'h3' }) {
  const { i18n } = useTranslation()
  const isBn = i18n.resolvedLanguage !== 'en'

  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2">
      <Tag className="m-0 text-15 font-semibold">
        {isBn ? bn : en}
        {isBn ? <span className="font-display text-13 font-normal text-app-muted"> {en}</span> : null}
      </Tag>
      {aside}
    </div>
  )
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div className="min-w-0 flex-1">
        <h1 className="m-0 text-fluid-21-28 font-bold tracking-heading">{title}</h1>
        {subtitle ? <p className="mt-0.5 mb-0 font-display text-15 text-app-muted">{subtitle}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2.5">{actions}</div> : null}
    </header>
  )
}

/** Pill filter chips (bookings status, package regions). */
export function Chips<T extends string>({ value, options, onChange, label }: { value: T; options: { value: T; label: string }[]; onChange: (value: T) => void; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-2">
      {options.map((option) => {
        const active = option.value === value
        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(option.value)}
            className={`border-chip cursor-pointer rounded-pill px-3.5 py-1.75 text-13 font-semibold ${active ? 'border-blue bg-blue text-white' : 'border-app-line bg-app-surface text-app-text hover:border-blue'}`}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  )
}

const badgeTones = {
  green: 'bg-green-tint text-green-deep',
  orange: 'bg-orange-tint text-amber',
  slate: 'bg-slate-tint text-silver',
  blue: 'bg-blue-tint text-blue-deep',
  red: 'bg-red-tint text-red',
} as const

export function Badge({ tone, children }: { tone: keyof typeof badgeTones; children: ReactNode }) {
  return <span className={`inline-flex items-center rounded-pill px-2.5 py-0.75 text-12 font-semibold whitespace-nowrap ${badgeTones[tone]}`}>{children}</span>
}

export function StatusBadge({ status }: { status: 'draft' | 'published' | 'archived' | 'scheduled' }) {
  const { t } = useTranslation()
  const tone = status === 'published' ? 'green' : status === 'scheduled' ? 'blue' : status === 'archived' ? 'slate' : 'orange'
  return <Badge tone={tone}>{t(`status.${status}`)}</Badge>
}

export function EmptyState({ title, note, action }: { title: string; note?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
      <strong className="text-16 font-semibold">{title}</strong>
      {note ? <span className="max-w-48ch text-13 text-app-muted">{note}</span> : null}
      {action ? <div className="pt-2">{action}</div> : null}
    </div>
  )
}

export function Loading() {
  const { t } = useTranslation()
  return (
    <div role="status" className="px-4 py-10 text-center text-13 text-app-muted">
      {t('common.loading')}
    </div>
  )
}

/** ▲ ▼ reorder controls, as on the prototype's block list — keyboard-accessible, no drag needed. */
export function ReorderButtons({ index, count, onMove, label }: { index: number; count: number; onMove: (from: number, to: number) => void; label: string }) {
  const { t } = useTranslation()
  return (
    <span className="flex shrink-0 gap-1.25">
      <button type="button" disabled={index === 0} onClick={() => onMove(index, index - 1)} aria-label={t('common.moveUp', { item: label })} className="size-6.5 cursor-pointer rounded-7 border border-app-line bg-transparent text-11 text-app-muted hover:border-blue hover:text-blue disabled:cursor-default disabled:opacity-35">
        ▲
      </button>
      <button type="button" disabled={index === count - 1} onClick={() => onMove(index, index + 1)} aria-label={t('common.moveDown', { item: label })} className="size-6.5 cursor-pointer rounded-7 border border-app-line bg-transparent text-11 text-app-muted hover:border-blue hover:text-blue disabled:cursor-default disabled:opacity-35">
        ▼
      </button>
    </span>
  )
}
