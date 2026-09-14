import { controlClass, parseNumber } from './controls'
import { useId, useState, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'

type FieldProps = { label: ReactNode; error?: string; hint?: ReactNode; children: (id: string, describedBy: string | undefined) => ReactNode; className?: string }

/** Label, control, then either the inline error (red) or a hint. */
export function Field({ label, error, hint, children, className = '' }: FieldProps) {
  const id = useId()
  const noteId = `${id}-note`
  return (
    <div className={`flex min-w-0 flex-col gap-1.25 ${className}`}>
      <label htmlFor={id} className="text-13 text-app-muted">
        {label}
      </label>
      {children(id, error || hint ? noteId : undefined)}
      {error ? (
        <span id={noteId} role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : hint ? (
        <span id={noteId} className="text-12 text-app-muted">
          {hint}
        </span>
      ) : null}
    </div>
  )
}

type TextProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> & { label: ReactNode; value: string | null | undefined; onChange: (value: string) => void; error?: string; hint?: ReactNode }

export function TextInput({ label, value, onChange, error, hint, className, ...props }: TextProps) {
  return (
    <Field label={label} error={error} hint={hint} className={className}>
      {(id, describedBy) => (
        <input id={id} aria-describedby={describedBy} aria-invalid={!!error} value={value ?? ''} onChange={(event) => onChange(event.target.value)} className={controlClass(!!error)} {...props} />
      )}
    </Field>
  )
}

type AreaProps = Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'value' | 'onChange'> & { label: ReactNode; value: string | null | undefined; onChange: (value: string) => void; error?: string; hint?: ReactNode }

export function TextArea({ label, value, onChange, error, hint, className, rows = 3, ...props }: AreaProps) {
  return (
    <Field label={label} error={error} hint={hint} className={className}>
      {(id, describedBy) => (
        <textarea id={id} rows={rows} aria-describedby={describedBy} aria-invalid={!!error} value={value ?? ''} onChange={(event) => onChange(event.target.value)} className={`${controlClass(!!error)} resize-y leading-1.55`} {...props} />
      )}
    </Field>
  )
}

type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'value' | 'onChange'> & { label: ReactNode; value: string; onChange: (value: string) => void; options: { value: string; label: string }[]; error?: string; hint?: ReactNode }

export function SelectInput({ label, value, onChange, options, error, hint, className, ...props }: SelectProps) {
  return (
    <Field label={label} error={error} hint={hint} className={className}>
      {(id, describedBy) => (
        <select id={id} aria-describedby={describedBy} aria-invalid={!!error} value={value} onChange={(event) => onChange(event.target.value)} className={controlClass(!!error)} {...props}>
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      )}
    </Field>
  )
}

type NumberProps = Omit<TextProps, 'value' | 'onChange' | 'type'> & { value: number | null | undefined; onChange: (value: number | null) => void; preview?: (value: number) => ReactNode }

/**
 * Numeric input. Typed as text so Bengali digits and thousands separators are accepted; the formatted value
 * (e.g. "৳ ৭৫,০০০") shows underneath so the editor can check the amount at a glance.
 */
export function NumberInput({ value, onChange, preview, hint, ...props }: NumberProps) {
  // The typed text is kept as typed ("75000." or Bengali digits) and only replaced when the value changes
  // from outside, e.g. when the form resets.
  const [draft, setDraft] = useState(value === null || value === undefined ? '' : String(value))
  if ((parseNumber(draft) ?? null) !== (value ?? null)) {
    setDraft(value === null || value === undefined ? '' : String(value))
  }

  return (
    <TextInput
      {...props}
      inputMode="decimal"
      value={draft}
      onChange={(raw) => {
        setDraft(raw)
        onChange(parseNumber(raw))
      }}
      hint={hint ?? (preview && value !== null && value !== undefined ? preview(value) : undefined)}
    />
  )
}

export function Switch({ label, checked, onChange, hint }: { label: ReactNode; checked: boolean; onChange: (checked: boolean) => void; hint?: ReactNode }) {
  return (
    <label className="flex cursor-pointer items-center gap-2.5 text-14">
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={`relative h-5.5 w-10 shrink-0 cursor-pointer rounded-pill transition-colors ${checked ? 'bg-green' : 'bg-app-line'}`}
      >
        <span className={`absolute top-0.75 size-4 rounded-full bg-white shadow-knob transition-all ${checked ? 'left-5.25' : 'left-0.75'}`} />
      </button>
      <span className="flex flex-col">
        {label}
        {hint ? <span className="text-12 text-app-muted">{hint}</span> : null}
      </span>
    </label>
  )
}

/** Two controls side by side (Bangla | English), stacking on narrow screens. */
export function Pair({ children }: { children: ReactNode }) {
  return <div className="grid-auto-fit-260 grid gap-3">{children}</div>
}
