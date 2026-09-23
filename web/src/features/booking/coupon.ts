'use client';

import { useEffect } from 'react';

import { checkCoupon, type CouponCheckPayload } from '@/lib/booking-api';
import { useBooking, type BookingState } from '@/state/booking';

/**
 * The booking form's coupon (docs/coupons.md): only a code goes to the API, which checks it against the booking and
 * answers with its own discount. The answer holds only for the booking it was checked against — the package, the
 * travellers, room, hotel category and add-ons, the lead's number and the passports typed — so a change to any of them
 * checks the code again.
 */

type Booking = Pick<BookingState, 'packageSlug' | 'pax' | 'room' | 'addons' | 'travellers'>;

const passports = (booking: Booking) =>
  booking.travellers
    .map((traveller) => traveller.passport.replace(/\s+/g, '').toUpperCase())
    .filter(Boolean)
    .sort();

/** Everything a coupon's answer depends on, as one string: a different string means checking again. */
export function couponBasis(booking: Booking, hotelCategory: string | null): string {
  return JSON.stringify([booking.packageSlug, booking.pax, booking.room, hotelCategory, [...booking.addons].sort(), booking.travellers[0]?.phone.trim() ?? '', passports(booking)]);
}

export function couponPayload(booking: Booking, hotelCategory: string | null, code: string, locale: 'bn' | 'en'): CouponCheckPayload {
  return {
    code: code.trim(),
    package_slug: booking.packageSlug,
    pax: booking.pax,
    room: booking.room,
    hotel_category: hotelCategory,
    addons: booking.addons,
    phone: booking.travellers[0]?.phone.trim() || null,
    passport_numbers: passports(booking),
    locale,
  };
}

/** Only the newest check's answer counts: one started later is for the booking as it is now. */
let newestCheck = 0;

/**
 * Checks a code for the booking as it is now and keeps the answer in the booking store: accepted, the API's discount;
 * refused, the reason and no coupon. `messages` are the form's own words for when the API couldn't be asked.
 */
export async function applyCoupon(code: string, hotelCategory: string | null, locale: 'bn' | 'en', messages: { unavailable: string; rateLimited: string }): Promise<void> {
  const ticket = ++newestCheck;
  const store = useBooking.getState();
  const basis = couponBasis(store, hotelCategory);
  store.setCouponCheck({ status: 'checking', message: null });
  const result = await checkCoupon(couponPayload(store, hotelCategory, code, locale));
  if (ticket !== newestCheck) return;
  const latest = useBooking.getState();
  if (!result.ok) {
    latest.setCoupon(null);
    latest.setCouponCheck({ status: 'refused', message: result.reason === 'rate_limited' ? messages.rateLimited : messages.unavailable });
    return;
  }
  if (!result.data.valid) {
    latest.setCoupon(null);
    latest.setCouponCheck({ status: 'refused', message: result.data.message });
    return;
  }
  latest.setCoupon({ code: result.data.code, discount: result.data.discount, basis });
  latest.setCouponCheck({ status: 'idle', message: null });
}

/** Takes the coupon off; an answer still on its way is ignored. */
export function removeCoupon() {
  newestCheck++;
  const store = useBooking.getState();
  store.setCoupon(null);
  store.setCouponCheck({ status: 'idle', message: null });
}

/** While the form is open: when the booking changes under an applied coupon, check the code again. */
export function useCouponSync(hotelCategory: string | null, locale: 'bn' | 'en', messages: { unavailable: string; rateLimited: string }) {
  const booking = useBooking();
  const basis = couponBasis(booking, hotelCategory);
  const code = booking.coupon && booking.coupon.basis !== basis ? booking.coupon.code : null;
  useEffect(() => {
    if (code !== null) void applyCoupon(code, hotelCategory, locale, messages);
    // Only a new basis (or a new code) is a reason to ask again; the messages are the same words every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [basis, code]);
}

/** The discount to price with: the coupon's, while its answer still describes this booking. */
export function couponDiscountFor(booking: Pick<BookingState, 'coupon'> & Booking, hotelCategory: string | null): number {
  return booking.coupon && booking.coupon.basis === couponBasis(booking, hotelCategory) ? booking.coupon.discount : 0;
}
