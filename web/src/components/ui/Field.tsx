import type { ReactNode } from 'react';

interface FieldProps {
  label: ReactNode;
  error?: string | null;
  children: ReactNode;
  className?: string;
  /** "compact": search and air-quote forms. "form": booking, contact and sign-in forms. */
  variant?: 'compact' | 'form';
}

/** Label above, control, inline error below. Errors never fail silently. */
export function Field({ label, error, children, className = '', variant = 'compact' }: FieldProps) {
  const labelText = variant === 'compact' ? 'text-12 font-semibold' : 'text-14';
  return (
    <label className={`flex flex-col gap-1.5 text-muted ${labelText} ${className}`}>
      {label}
      {children}
      {error ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : null}
    </label>
  );
}

/** Classes for inputs and selects; the border turns red on the offending control. */
export function controlClass(invalid = false, variant: 'compact' | 'form' = 'compact'): string {
  const shape = variant === 'compact' ? 'h-11 rounded-11 px-3 text-14' : 'rounded-10 p-3 text-15';
  return `${shape} w-full min-w-0 border bg-white font-sans font-normal text-ink outline-none transition-colors focus-visible:border-blue focus-visible:ring-2 focus-visible:ring-blue/20 ${
    invalid ? 'border-red bg-red-tint/40' : 'border-input'
  }`;
}
