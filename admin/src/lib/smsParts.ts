/**
 * How many SMS parts a message costs, so the composer can say it before it is sent (3GPP TS 23.038). Text that fits the
 * GSM 7-bit alphabet goes as 160 septets, or 153 per part once split; anything else — Bangla, ৳, emoji — goes as UCS-2:
 * 70 units, or 67 per part.
 *
 * The server counts the same way in App\Support\Sms\SmsParts and its count is the one that is billed; that one also
 * keeps Bangla conjuncts and emoji sequences whole across a part boundary, which can add a part the estimate here
 * misses. The two agree on plain text, which is what a payment reminder is.
 */
const GSM7_BASIC = "@£$¥èéùìò\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà"

/** Escaped characters: two septets each. */
const GSM7_EXTENDED = '\f^{}\\[~]|€'

export type SmsCount = { encoding: 'gsm7' | 'ucs2'; units: number; parts: number; perPart: number }

export function smsParts(text: string): SmsCount {
  const chars = [...text]
  const gsm = chars.every((char) => GSM7_BASIC.includes(char) || GSM7_EXTENDED.includes(char))
  const size = (char: string) => (gsm ? (GSM7_EXTENDED.includes(char) ? 2 : 1) : ((char.codePointAt(0) ?? 0) > 0xffff ? 2 : 1))
  const [single, perPart] = gsm ? [160, 153] : [70, 67]
  const units = chars.reduce((total, char) => total + size(char), 0)

  if (units === 0) return { encoding: gsm ? 'gsm7' : 'ucs2', units: 0, parts: 0, perPart: single }
  if (units <= single) return { encoding: gsm ? 'gsm7' : 'ucs2', units, parts: 1, perPart: single }

  // Parts are counted by packing, not by dividing: a two-unit character is never split across a part boundary.
  let parts = 1
  let used = 0
  for (const char of chars) {
    const width = size(char)
    if (used + width > perPart) {
      parts++
      used = 0
    }
    used += width
  }

  return { encoding: gsm ? 'gsm7' : 'ucs2', units, parts, perPart }
}
