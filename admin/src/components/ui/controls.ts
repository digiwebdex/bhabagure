/** Class and parsing helpers shared by the form controls (kept out of fields.tsx for fast refresh). */

export const controlClass = (invalid = false) =>
  `w-full rounded-9 border bg-app-surface-2 px-3 py-2.5 text-14 text-app-text outline-none focus-visible:border-blue focus-visible:ring-2 focus-visible:ring-blue/20 ${invalid ? 'border-red' : 'border-app-line'}`

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯'

/** Staff type on Bangla keyboards: Bengali digits are accepted and stored as numbers. */
export function parseNumber(raw: string): number | null {
  const latin = raw.replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit))).replace(/[,\s]/g, '')
  if (latin === '') return null
  const value = Number(latin)
  return Number.isFinite(value) ? value : null
}
