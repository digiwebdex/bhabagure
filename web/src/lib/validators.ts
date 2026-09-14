import { localizeDigits } from '@bhabaghure/format';

/**
 * Form checks shared by the website's forms. Every check first turns Bengali digits typed on a
 * Bangla keyboard into Latin ones (০১৭… → 017…), through the one digit function.
 * The API validates again; these exist so errors show inline before submitting.
 */

export const normalizeDigits = (value: string) => localizeDigits(value, 'en').trim();

/** Bangladeshi mobile → 8801XXXXXXXXX, or null. Accepts 01…, 8801…, +8801…, spaces and dashes. */
export function normalizeBdMobile(value: string): string | null {
  const digits = normalizeDigits(value).replace(/[\s-]/g, '');
  const match = /^(?:\+?88)?(01[3-9]\d{8})$/.exec(digits);
  return match ? `88${match[1]}` : null;
}

export function isEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value.trim());
}

/** Bangladeshi passport numbers: machine-readable (2 letters + 7 digits) or e-passport (1 letter + 8 digits). */
export function isPassportNumber(value: string): boolean {
  return /^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/i.test(normalizeDigits(value));
}

/** "DD/MM/YYYY" → "YYYY-MM-DD", or null when it isn't a real calendar date. */
export function parseDayMonthYear(value: string): string | null {
  const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(normalizeDigits(value));
  if (!match) return null;
  const [day, month, year] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** ISO date plus `months`, keeping the day where the month allows. */
export function addMonths(isoDate: string, months: number): string {
  const [y, m, d] = isoDate.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1 + months, 1));
  const lastDay = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
  date.setUTCDate(Math.min(d, lastDay));
  return date.toISOString().slice(0, 10);
}

export function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}
