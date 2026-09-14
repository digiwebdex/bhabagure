import { addMonths, isEmail, isPassportNumber, normalizeBdMobile, parseDayMonthYear, todayIso } from '@/lib/validators';
import type { BookingState, TravellerDraft } from '@/state/booking';

type Translate = (key: string, values?: Record<string, string | number>) => string;

export type PackageStepErrors = { date?: string };
export type TravellerErrors = Partial<Record<keyof TravellerDraft, string>>;

export function validatePackageStep(booking: Pick<BookingState, 'date'>, tv: Translate) {
  const errors: PackageStepErrors = {};
  if (!booking.date) errors.date = tv('required');
  else if (booking.date < todayIso()) errors.date = tv('dateFuture');
  return { errors, invalid: Object.keys(errors).length > 0 };
}

export function validateTravellers(booking: Pick<BookingState, 'travellers'>, tv: Translate) {
  const errors: TravellerErrors[] = booking.travellers.map((traveller, index) => {
    const e: TravellerErrors = {};
    if (!traveller.name.trim()) e.name = tv('required');
    if (!traveller.passport.trim()) e.passport = tv('required');
    else if (!isPassportNumber(traveller.passport)) e.passport = tv('passport');

    const dob = parseDayMonthYear(traveller.dob);
    if (!traveller.dob.trim()) e.dob = tv('required');
    else if (!dob) e.dob = tv('date');
    else if (dob >= todayIso()) e.dob = tv('datePast');

    const expiry = parseDayMonthYear(traveller.expiry);
    if (!traveller.expiry.trim()) e.expiry = tv('required');
    else if (!expiry) e.expiry = tv('date');
    else if (expiry <= todayIso()) e.expiry = tv('dateFuture');

    // A scanned value whose check digit failed must be checked by the traveller, never accepted silently.
    for (const field of ['passport', 'dob', 'expiry'] as const) {
      if (traveller.confirm[field] && !e[field]) e[field] = tv('confirmScan');
    }

    // The lead traveller needs a mobile number; the others may leave it blank.
    if (index === 0 && !traveller.phone.trim()) e.phone = tv('required');
    else if (traveller.phone.trim() && !normalizeBdMobile(traveller.phone)) e.phone = tv('phone');
    if (traveller.email.trim() && !isEmail(traveller.email)) e.email = tv('email');
    return e;
  });
  return { errors, invalid: errors.some((e) => Object.keys(e).length > 0) };
}

/** Passport needs 6 months of validity left on the travel date. A warning, not a blocker — staff can help renew. */
export function expiresTooSoon(expiry: string, travelDate: string): boolean {
  const iso = parseDayMonthYear(expiry);
  if (!iso || !travelDate) return false;
  return iso < addMonths(travelDate, 6);
}
