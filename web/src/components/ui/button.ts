/**
 * Button looks from the website prototype, as class strings so they apply to <button>, <a> and
 * next-intl <Link> alike. Gradients interpolate in sRGB, like the prototype's CSS.
 *
 * Variants set colour only and sizes set padding and type only, so a caller adding a shadow or
 * choosing size 'none' never ends up with two conflicting utilities (Tailwind resolves conflicts by
 * stylesheet order, not class order).
 */
export type ButtonVariant = 'cta' | 'primary' | 'success' | 'outline' | 'outlineInk' | 'outlineDark';
export type ButtonSize = 'sm' | 'md' | 'lg' | 'block' | 'none';

const base =
  'inline-flex cursor-pointer items-center justify-center gap-2 rounded-pill font-semibold whitespace-nowrap transition duration-200 disabled:cursor-not-allowed disabled:opacity-50';

const variants: Record<ButtonVariant, string> = {
  cta: 'bg-linear-135/srgb from-orange-bright to-orange-deep text-white hover:text-white',
  primary: 'bg-linear-135/srgb from-blue to-blue-deep text-white hover:text-white',
  success: 'bg-linear-135/srgb from-green to-green-deep text-white hover:text-white',
  outline: 'border border-input bg-white text-muted hover:border-orange hover:text-orange',
  outlineInk: 'border border-input bg-white text-ink hover:border-orange hover:text-orange',
  outlineDark: 'border border-hairline bg-white text-ink hover:border-whatsapp hover:text-green-deep',
};

const sizes: Record<ButtonSize, string> = {
  sm: 'px-4.5 py-2.25 text-13',
  md: 'px-5 py-2.75 text-14',
  lg: 'px-6.5 py-3 text-15',
  block: 'w-full px-4 py-3.25 text-15',
  none: '',
};

export function buttonClass(variant: ButtonVariant, size: ButtonSize = 'md', extra = ''): string {
  return [base, variants[variant], sizes[size], extra].filter(Boolean).join(' ');
}
