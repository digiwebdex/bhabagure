/**
 * Admin button looks from the prototype: orange gradient for the one main action, blue gradient for primary
 * actions inside cards, outlined for the rest. Class strings, so they apply to <button>, <a> and <Link>.
 */
export type ButtonVariant = 'cta' | 'primary' | 'outline' | 'ghost' | 'danger' | 'success'
export type ButtonSize = 'sm' | 'md' | 'icon'

const base =
  'inline-flex cursor-pointer items-center justify-center gap-2 rounded-10 font-semibold whitespace-nowrap transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue'

const variants: Record<ButtonVariant, string> = {
  cta: 'bg-linear-135/srgb from-orange-bright to-orange-deep text-white hover:text-white',
  primary: 'bg-linear-135/srgb from-blue to-blue-deep text-white hover:text-white',
  success: 'bg-linear-135/srgb from-green to-green-deep text-white hover:text-white',
  outline: 'border border-app-line bg-transparent text-app-text hover:border-blue hover:text-blue',
  ghost: 'bg-app-surface-2 text-app-text hover:text-blue',
  danger: 'border border-app-line bg-transparent text-amber hover:border-red hover:text-red',
}

const sizes: Record<ButtonSize, string> = {
  sm: 'px-3 py-1.75 text-12',
  md: 'px-4 py-2.5 text-13',
  icon: 'size-6.5 rounded-7 border border-app-line text-11 text-app-muted hover:border-blue hover:text-blue',
}

export function buttonClass(variant: ButtonVariant, size: ButtonSize = 'md', extra = ''): string {
  return [base, size === 'icon' ? '' : variants[variant], sizes[size], extra].filter(Boolean).join(' ')
}
