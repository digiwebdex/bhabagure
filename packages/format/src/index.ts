/**
 * The one place a number becomes text.
 *
 * Bangla mode renders Bengali digits (৳ ১,৫০,০০০); English mode renders Latin (৳ 1,50,000). "৳" is a currency
 * symbol, not a language choice, so both languages use it. Admin, website and portal all format through this module.
 * The Laravel API (invoice PDFs, messages) has a PHP twin held to the same `fixtures.json`, so the two cannot drift.
 *
 * Rules:
 * - Grouping is en-IN (1,50,000). It is computed here rather than by `Intl`, so every
 *   browser and the PHP twin produce byte-identical strings.
 * - Identifiers are not numbers. Booking references, invoice numbers, phone and
 *   passport numbers never go through `formatNumber` / `formatBdt`.
 * - Never store formatted strings. Format at render time only.
 * - SMS is the one place money reads "BDT" (`currency: 'code'`): "৳" is not in the GSM-7 alphabet, so it would turn
 *   every English SMS into Unicode at 70 characters a part. Only the API's SMS channel passes it.
 */

export type Locale = 'bn' | 'en';

export interface NumberFormatOptions {
  /** Fraction digits. `'auto'` (default): none for whole numbers, two otherwise. */
  decimals?: number | 'auto';
}

export interface MoneyFormatOptions extends NumberFormatOptions {
  /** `'symbol'` (default): "৳ ". `'code'`: "BDT " — for SMS only. */
  currency?: 'symbol' | 'code';
}

const BENGALI_DIGITS = '০১২৩৪৫৬৭৮৯';
const MINUS = '−';
const CURRENCY_PREFIX = { symbol: '৳ ', code: 'BDT ' } as const;
const LAKH = 100_000;
const CRORE = 10_000_000;
const SCALE_SUFFIX: Record<Locale, { lakh: string; crore: string }> = {
  bn: { lakh: ' লাখ', crore: ' কোটি' },
  en: { lakh: 'L', crore: 'Cr' },
};

/**
 * The single digit conversion. `bn`: Latin → Bengali. `en`: Bengali → Latin, which
 * also normalises digits typed on a Bangla keyboard (০১৭… → 017…).
 */
export function localizeDigits(text: string, locale: Locale): string {
  return locale === 'bn'
    ? text.replace(/[0-9]/g, (d) => BENGALI_DIGITS[Number(d)])
    : text.replace(/[০-৯]/g, (d) => String(BENGALI_DIGITS.indexOf(d)));
}

/** 1240000 → "১২,৪০,০০০" (bn) / "12,40,000" (en). */
export function formatNumber(value: number | string, locale: Locale, options: NumberFormatOptions = {}): string {
  const { negative, digits } = toGroupedDigits(value, options.decimals ?? 'auto');
  return (negative ? MINUS : '') + localizeDigits(digits, locale);
}

/**
 * Rates: 12 → "১২%" · 2.5 → "2.5%" · 1.85 → "1.85%". Up to two decimals, never trailing zeros, so a charge rate is
 * shown exactly as configured rather than rounded ("1.9%") or padded ("2.50%").
 */
export function formatPercent(value: number, locale: Locale): string {
  const n = toFiniteNumber(value);
  const places = Number.isInteger(n) ? 0 : Number.isInteger(Math.round(n * 100) / 10) ? 1 : 2;
  return `${formatNumber(n, locale, { decimals: places })}%`;
}

/** 75000 → "৳ ৭৫,০০০" (bn) / "৳ 75,000" (en). Negative amounts: "−৳ ৪৮,০০০". */
export function formatBdt(value: number | string, locale: Locale, options: MoneyFormatOptions = {}): string {
  const { negative, digits } = toGroupedDigits(value, options.decimals ?? 'auto');
  return (negative ? MINUS : '') + CURRENCY_PREFIX[options.currency ?? 'symbol'] + localizeDigits(digits, locale);
}

/**
 * Summary figures: 1420000 → "৳ 14.2L" / "৳ ১৪.২ লাখ"; 24000000 → "৳ 2.4Cr" / "৳ ২.৪ কোটি"; below one lakh the whole
 * amount in taka ("৳ 99,999"). One decimal, as the design computes it. Crore is chosen after rounding, so 99,96,000
 * reads "৳ 1.0Cr", never "৳ 100.0L". Rounding is done on whole taka in integers (half away from zero), so the PHP twin
 * gives the same digits for every amount.
 */
export function formatBdtCompact(value: number | string, locale: Locale, options: Pick<MoneyFormatOptions, 'currency'> = {}): string {
  const n = toFiniteNumber(value);
  const taka = Math.round(Math.abs(n)); // half away from zero for the magnitude
  const sign = n < 0 && taka !== 0 ? MINUS : '';
  const prefix = sign + CURRENCY_PREFIX[options.currency ?? 'symbol'];

  if (taka < LAKH) {
    return prefix + localizeDigits(groupIndian(String(taka)), locale);
  }
  const lakhTenths = Math.floor((taka + LAKH / 20) / (LAKH / 10));
  if (lakhTenths < 1000) {
    return prefix + tenths(lakhTenths, locale) + SCALE_SUFFIX[locale].lakh;
  }
  const croreTenths = Math.floor((taka + CRORE / 20) / (CRORE / 10));
  return prefix + tenths(croreTenths, locale) + SCALE_SUFFIX[locale].crore;
}

/** 142 → "14.2" / "১৪.২"; the whole part keeps en-IN grouping (12345 crore tenths → "1,234.5"). */
function tenths(count: number, locale: Locale): string {
  return localizeDigits(`${groupIndian(String(Math.floor(count / 10)))}.${count % 10}`, locale);
}

function toGroupedDigits(value: number | string, decimals: number | 'auto'): { negative: boolean; digits: string } {
  const n = toFiniteNumber(value);
  const places = decimals === 'auto' ? (Number.isInteger(n) ? 0 : 2) : decimals;
  if (!Number.isInteger(places) || places < 0 || places > 20) {
    throw new RangeError(`@bhabaghure/format: decimals must be an integer from 0 to 20, got ${String(decimals)}`);
  }

  // Rounds half away from zero, the same as PHP's number_format().
  const fixed = Math.abs(n).toFixed(places);
  const [whole, fraction] = fixed.split('.');

  return {
    negative: n < 0 && Number(fixed) !== 0, // never render "−0"
    digits: groupIndian(whole) + (fraction ? `.${fraction}` : ''),
  };
}

function toFiniteNumber(value: number | string): number {
  const n = typeof value === 'string' && value.trim() !== '' ? Number(value) : value;
  if (typeof n !== 'number' || !Number.isFinite(n)) {
    throw new TypeError(`@bhabaghure/format: not a finite number: ${JSON.stringify(value)}`);
  }
  if (Math.abs(n) >= 1e21) {
    throw new RangeError(`@bhabaghure/format: ${n} is too large to format exactly`);
  }
  return n;
}

const MONTHS: Record<Locale, readonly string[]> = {
  bn: ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'],
  en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
};

/**
 * "2026-09-10" → "১০ সেপ্টেম্বর ২০২৬" (bn) / "10 September 2026" (en).
 * Month names come from our own tables, not Intl, so the server, every browser and the PHP twin
 * produce the same string (ICU versions disagree on Bangla date punctuation).
 */
export function formatDate(isoDate: string, locale: Locale): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);
  const month = match ? Number(match[2]) : 0;
  const day = match ? Number(match[3]) : 0;
  if (!match || month < 1 || month > 12 || day < 1 || day > 31) {
    throw new TypeError(`@bhabaghure/format: expected a YYYY-MM-DD date, got ${JSON.stringify(isoDate)}`);
  }
  return localizeDigits(`${day} ${MONTHS[locale][month - 1]} ${match[1]}`, locale);
}

/** "1240000" → "12,40,000": the last three digits, then groups of two. */
function groupIndian(whole: string): string {
  if (whole.length <= 3) return whole;
  const head = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
  return `${head},${whole.slice(-3)}`;
}
