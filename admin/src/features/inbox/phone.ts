const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯'

/**
 * A WhatsApp number as staff type it, as digits with the country code, or null; the API's Phone::normalizeWhatsApp
 * (docs/admin-inbox.md §8). A Bangladeshi mobile in any of its forms (01711-000000, +880 1711…), or another country's
 * written with its + or 00 code (+977 981-2345678).
 */
export function whatsAppNumber(value: string): string | null {
  const text = value.replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit))).replace(/[\s\-().]/g, '')
  const bd = /^(?:\+?88)?(01[3-9]\d{8})$/.exec(text)
  if (bd) return `88${bd[1]}`
  const other = /^(?:\+|00)([1-9]\d{7,14})$/.exec(text)
  return other ? other[1] : null
}
