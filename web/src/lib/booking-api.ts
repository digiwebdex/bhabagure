'use client';

/**
 * Booking, payment and passport-scan calls to the Laravel API (docs/phase-3-booking.md §2, §4, §5).
 *
 * A guest's booking is opened with its private token. The token is kept in sessionStorage for the return from
 * SSLCommerz (same tab), and shown once as a private link with the token in the URL fragment — fragments are never
 * sent to a server, so the token doesn't land in access logs.
 */

export type ScanField<T = string> = { value: T | null; confirm: boolean };

export type PassportScanResult = {
  token: string;
  status: 'read' | 'partial' | 'unavailable';
  fields: {
    fullName: string;
    passportNumber: ScanField;
    dateOfBirth: ScanField;
    passportExpiry: ScanField;
    nationality: { value: string; iso2: string | null };
    compositeValid: boolean;
  } | null;
};

export type PublicBooking = {
  reference: string;
  status: 'inquiry' | 'confirmed' | 'completed' | 'cancelled';
  paymentStatus: 'unpaid' | 'partial' | 'paid';
  packageTitle: string;
  travelStart: string | null;
  travelEnd: string | null;
  pax: number;
  room: string;
  lines: {
    kind: string;
    code: string | null;
    title: string;
    quantity: number;
    unitPrice: number;
    amount: number;
  }[];
  /** The whole discount; a coupon's part of it is `coupon.discount`. */
  discount: number;
  /** The coupon in this booking's price (docs/coupons.md), as its own line; null without one. */
  coupon: { code: string; discount: number } | null;
  chargePercent: number;
  serviceCharge: number;
  total: number;
  paid: number;
  due: number;
  travellers: { name: string }[];
  invoice: {
    number: string;
    issuedOn: string;
    url: string;
    pdfUrl: string;
  } | null;
  payment: {
    canPay: boolean;
    /** Paying the balance online now: the balance, the online payment charge line, and the exact total to charge. */
    online: { amount: number; chargePercent: number; charge: number; total: number };
    lastAttempt: { status: string; amount: number; at: string } | null;
    /** Whether the built-in SSLCommerz checkout is live; until it is, the payment link stands in for it. */
    checkout: boolean;
    /** How to pay the balance by hand, from Admin → Site settings → Payment; null when nothing is set or nothing is due. */
    manual: ManualPayment | null;
  };
};

/** api/app/Support/Payments/PaymentOptions.php: each method with the exact amount to send. */
export type ManualPayment = {
  amount: number;
  /** Every bank account the agency takes transfers into, in the order set in Admin (up to three). */
  banks: { bankName: string; accountName: string; accountNumber: string; branch: string; routingNumber: string; transferType: string }[];
  /** The SSLCommerz payment form for card, mobile banking and EMI; only while the built-in checkout is off. */
  link: string | null;
  bkash: { number: string; chargePercent: number; charge: number; total: number } | null;
};

export type BookingPayload = {
  package_slug: string;
  travel_date: string;
  pax: number;
  room: string;
  /** '3', '4' or '5' for a package priced by hotel category (Phase 8 §4.D). */
  hotel_category: string | null;
  addons: string[];
  travellers: {
    /** Required for the lead traveller only. */
    name: string | null;
    passport_number: string | null;
    date_of_birth: string | null;
    passport_expiry: string | null;
    phone: string | null;
    email: string | null;
    passport_scan_token: string | null;
    ocr_filled: boolean;
  }[];
  expected_total: number;
  terms_accepted: true;
  locale: 'bn' | 'en';
  /** One per booking attempt: sent again, the API answers already_created instead of booking twice. */
  idempotency_key: string;
  /** Only the code (docs/coupons.md): the API works the discount out, and refuses a total it didn't. */
  coupon_code: string | null;
};

export type ApiFailure =
  | { ok: false; reason: 'price_changed'; total: number }
  | { ok: false; reason: 'already_created'; reference: string }
  | { ok: false; reason: 'seats_unavailable'; available: number }
  /** The coupon stopped working between Apply and booking (used up, expired, switched off): nothing was booked. */
  | { ok: false; reason: 'coupon_invalid'; message: string }
  | {
      ok: false;
      reason: 'payment_unavailable' | 'gateway_unavailable' | 'not_found' | 'rate_limited' | 'invalid' | 'unavailable' | 'failed';
      message?: string;
    };

const base = () => process.env.NEXT_PUBLIC_API_URL ?? '';

async function call<T>(path: string, init: RequestInit & { token?: string; locale: string }): Promise<{ ok: true; data: T } | ApiFailure> {
  if (!base()) return { ok: false, reason: 'unavailable' };
  let res: Response;
  try {
    res = await fetch(`${base()}/api/v1/public/${path}`, {
      ...init,
      headers: {
        Accept: 'application/json',
        'X-Locale': init.locale,
        ...(init.body && !(init.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
        ...(init.token ? { 'X-Booking-Token': init.token } : {}),
      },
    });
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
  const body = (await res.json().catch(() => null)) as {
    data?: T;
    code?: string;
    message?: string;
    quote?: { total: number };
    payment?: { total: number };
    available?: number;
    reference?: string;
  } | null;
  if (res.ok && body?.data !== undefined) return { ok: true, data: body.data };
  if (body?.code === 'already_created' && body.reference) return { ok: false, reason: 'already_created', reference: body.reference };
  if (body?.code === 'coupon_invalid' && body.message) return { ok: false, reason: 'coupon_invalid', message: body.message };
  if (body?.code === 'price_changed' && (body.quote || body.payment)) return { ok: false, reason: 'price_changed', total: (body.payment ?? body.quote)!.total };
  if (body?.code === 'seats_unavailable')
    return {
      ok: false,
      reason: 'seats_unavailable',
      available: body.available ?? 0,
    };
  if (body?.code === 'payment_unavailable' || body?.code === 'gateway_unavailable') return { ok: false, reason: body.code, message: body.message };
  if (res.status === 404) return { ok: false, reason: 'not_found' };
  if (res.status === 429) return { ok: false, reason: 'rate_limited' };
  if (res.status === 422) return { ok: false, reason: 'invalid', message: body?.message };
  return { ok: false, reason: 'failed' };
}

export const createBooking = (payload: BookingPayload) =>
  call<PublicBooking & { accessToken: string }>('bookings', {
    method: 'POST',
    body: JSON.stringify(payload),
    locale: payload.locale,
  });

/** What the booking form's Apply button sends: the booking's choices, the lead's number and the passports typed so far. */
export type CouponCheckPayload = {
  code: string;
  package_slug: string;
  pax: number;
  room: string;
  hotel_category: string | null;
  addons: string[];
  phone: string | null;
  passport_numbers: string[];
  locale: 'bn' | 'en';
};

/** The API's answer (api/app/Http/Controllers/Api/V1/Public/PublicCouponController.php): its own discount, never ours. */
export type CouponCheck =
  | {
      valid: true;
      code: string;
      kind: 'public' | 'passport';
      discountType: 'percent' | 'fixed';
      discountValue: number;
      maxDiscount: number | null;
      minAmount: number | null;
      discount: number;
      subtotal: number;
      originalTotal: number;
      total: number;
      message: string;
    }
  | { valid: false; code: string; reason: string; message: string; subtotal: number; total: number };

export const checkCoupon = (payload: CouponCheckPayload) =>
  call<CouponCheck>('coupons/check', {
    method: 'POST',
    body: JSON.stringify(payload),
    locale: payload.locale,
  });

export const getBooking = (reference: string, token: string, locale: string) =>
  call<PublicBooking>(`bookings/${encodeURIComponent(reference)}`, {
    token,
    locale,
  });

/** `expectedTotal` is exactly what the customer was shown; the API starts nothing if it no longer matches. */
export const startPayment = (reference: string, token: string, method: string, expectedTotal: number, locale: string) =>
  call<{ redirectUrl: string; amount: number; charge: number; total: number }>(`bookings/${encodeURIComponent(reference)}/payments`, {
    method: 'POST',
    body: JSON.stringify({ method, expected_total: expectedTotal }),
    token,
    locale,
  });

export function uploadPassportScan(file: File, locale: string) {
  const form = new FormData();
  form.append('file', file);
  return call<PassportScanResult>('passport-scans', {
    method: 'POST',
    body: form,
    locale,
  });
}

const storageKey = (reference: string) => `bh-booking:${reference}`;

export function rememberBookingToken(reference: string, token: string) {
  try {
    sessionStorage.setItem(storageKey(reference), token);
  } catch {
    // Private mode: the private link still works.
  }
}

const justBookedKey = 'bh-booking:just-booked';

/** Marks a booking as made a moment ago in this tab, so its page opens with the congratulations. */
export function markJustBooked(reference: string) {
  try {
    sessionStorage.setItem(justBookedKey, JSON.stringify({ reference, at: Date.now() }));
  } catch {
    // Private mode: the page still shows the booking, only without the greeting.
  }
}

/** The booking made in this tab within the last half hour — long enough to pay, short enough not to greet forever. */
export function isJustBooked(reference: string): boolean {
  try {
    const mark = JSON.parse(sessionStorage.getItem(justBookedKey) ?? 'null') as { reference: string; at: number } | null;
    return mark?.reference === reference && Date.now() - mark.at < 30 * 60_000;
  } catch {
    return false;
  }
}

/** The token from the URL fragment (#t=…) or this tab's session. */
export function recallBookingToken(reference: string): string | null {
  const fromHash = new URLSearchParams(window.location.hash.slice(1)).get('t');
  if (fromHash) {
    rememberBookingToken(reference, fromHash);
    return fromHash;
  }
  try {
    return sessionStorage.getItem(storageKey(reference));
  } catch {
    return null;
  }
}

/** "2030-01-31" → "31/01/2030", the format the traveller form uses. */
export const isoToDayMonthYear = (iso: string) => iso.split('-').reverse().join('/');
