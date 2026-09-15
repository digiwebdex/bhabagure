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
  discount: number;
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
  };
};

export type BookingPayload = {
  package_slug: string;
  travel_date: string;
  pax: number;
  room: string;
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
};

export type ApiFailure =
  | { ok: false; reason: 'price_changed'; total: number }
  | { ok: false; reason: 'seats_unavailable'; available: number }
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
  } | null;
  if (res.ok && body?.data !== undefined) return { ok: true, data: body.data };
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
