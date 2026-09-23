'use client';

import { create } from 'zustand';

import { clampTravellers, type HotelCategory, type RoomType } from '@bhabaghure/pricing';

/** Fields a passport scan can fill. `confirm`: the MRZ check digit failed, so the traveller must check it. */
export type ScannableField = 'passport' | 'dob' | 'expiry';

export interface TravellerDraft {
  name: string;
  passport: string;
  dob: string;
  expiry: string;
  phone: string;
  email: string;
  /** Name of the chosen scan file. */
  fileName: string;
  scan: 'idle' | 'uploading' | 'read' | 'partial' | 'unavailable' | 'error';
  /** Single-use token from POST /public/passport-scans, sent with the booking to attach the scan. */
  scanToken: string | null;
  /** Filled from the scan and not yet edited. */
  ocrFilled: boolean;
  /** Filled from the scan but failed its check digit: shown as "please confirm" until edited. */
  confirm: Partial<Record<ScannableField, boolean>>;
}

export type BookingStep = 1 | 2 | 3 | 4;
export type PaymentMethod = 'bkash' | 'nagad' | 'card' | 'bank';

/**
 * The booking form. Held in memory only — traveller and passport details are never written to localStorage. They leave
 * the browser once, when the booking is created on the payment step.
 */
export interface BookingState {
  open: boolean;
  step: BookingStep;
  packageSlug: string;
  date: string;
  pax: number;
  room: RoomType;
  /** For a package with a price grid: the hotel category, else null. The modal falls back to the package's default. */
  hotelCategory: HotelCategory | null;
  addons: string[];
  travellers: TravellerDraft[];
  terms: boolean;
  method: PaymentMethod;
  /** Validation messages show once the traveller tries to move on. */
  attempted: Record<BookingStep, boolean>;
  /**
   * One key per booking attempt, sent with the booking: a second click or a retry is the same booking, not a new one
   * (2026-09-19). A new attempt — opening the form again — gets a new key.
   */
  attemptKey: string;
  /** The booking this attempt made, kept here so the payment step still knows it if it is drawn again. */
  created: CreatedBooking | null;
  /** A coupon the API accepted for this booking as it then was (docs/coupons.md); null without one. */
  coupon: AppliedCoupon | null;
  /** The coupon box: checking a code, or why the last one was refused. */
  couponCheck: CouponCheckState;

  start: (options: { packageSlug: string; date?: string; pax?: number; maxPax: number; hotelCategory?: HotelCategory | null }) => void;
  close: () => void;
  goBack: () => void;
  goNext: () => void;
  markAttempted: (step: BookingStep) => void;
  setPackage: (slug: string) => void;
  setDate: (date: string) => void;
  setPax: (pax: number, max: number) => void;
  setRoom: (room: RoomType) => void;
  setHotelCategory: (category: HotelCategory) => void;
  toggleAddon: (code: string) => void;
  updateTraveller: (index: number, patch: Partial<TravellerDraft>) => void;
  setTerms: (terms: boolean) => void;
  setMethod: (method: PaymentMethod) => void;
  setCreated: (created: CreatedBooking) => void;
  setCoupon: (coupon: AppliedCoupon | null) => void;
  setCouponCheck: (check: CouponCheckState) => void;
  /** The booking is made: close the form and let go of the travellers' details. */
  finish: () => void;
}

/**
 * The API's answer for a code: its own discount — the form never works one out — and what it checked the code against.
 * When the booking changes (travellers, room, add-ons, passports), the code is checked again (features/booking/coupon.ts).
 */
export interface AppliedCoupon {
  code: string;
  discount: number;
  /** couponBasis() of the booking it was checked for. */
  basis: string;
}

export type CouponCheckState = { status: 'idle' | 'checking' | 'refused'; message: string | null };

const idleCheck = (): CouponCheckState => ({ status: 'idle', message: null });

export interface CreatedBooking {
  reference: string;
  token: string;
  /** Whether the built-in online checkout takes the payment next, as the API said. */
  checkout: boolean;
}

const newAttemptKey = () => crypto.randomUUID();

export const emptyTraveller = (): TravellerDraft => ({
  name: '',
  passport: '',
  dob: '',
  expiry: '',
  phone: '',
  email: '',
  fileName: '',
  scan: 'idle',
  scanToken: null,
  ocrFilled: false,
  confirm: {},
});

const resize = (travellers: TravellerDraft[], pax: number) => Array.from({ length: pax }, (_, i) => travellers[i] ?? emptyTraveller());

const noAttempts = (): Record<BookingStep, boolean> => ({
  1: false,
  2: false,
  3: false,
  4: false,
});

export const useBooking = create<BookingState>()((set) => ({
  open: false,
  step: 1,
  packageSlug: '',
  date: '',
  pax: 2,
  room: 'twin',
  hotelCategory: null,
  addons: [],
  travellers: resize([], 2),
  terms: false,
  method: 'bkash',
  attempted: noAttempts(),
  attemptKey: '',
  created: null,
  coupon: null,
  couponCheck: idleCheck(),

  start: ({ packageSlug, date, pax, maxPax, hotelCategory }) =>
    set((state) => {
      const nextPax = clampTravellers(pax ?? state.pax, maxPax);
      return {
        open: true,
        step: 1,
        packageSlug,
        hotelCategory: hotelCategory ?? null,
        date: date ?? state.date,
        pax: nextPax,
        travellers: resize(state.travellers, nextPax),
        attempted: noAttempts(),
        attemptKey: newAttemptKey(),
        created: null,
        coupon: null,
        couponCheck: idleCheck(),
      };
    }),
  close: () => set({ open: false }),
  goBack: () =>
    set((state) => ({
      step: state.step > 1 ? ((state.step - 1) as BookingStep) : state.step,
    })),
  goNext: () =>
    set((state) => ({
      step: state.step < 4 ? ((state.step + 1) as BookingStep) : state.step,
    })),
  markAttempted: (step) => set((state) => ({ attempted: { ...state.attempted, [step]: true } })),
  setPackage: (packageSlug) => set({ packageSlug, hotelCategory: null }),
  setDate: (date) => set({ date }),
  setPax: (pax, max) =>
    set((state) => {
      const nextPax = clampTravellers(pax, max);
      return { pax: nextPax, travellers: resize(state.travellers, nextPax) };
    }),
  setRoom: (room) => set({ room }),
  setHotelCategory: (hotelCategory) => set({ hotelCategory }),
  toggleAddon: (code) =>
    set((state) => ({
      addons: state.addons.includes(code) ? state.addons.filter((c) => c !== code) : [...state.addons, code],
    })),
  updateTraveller: (index, patch) =>
    set((state) => ({
      travellers: state.travellers.map((t, i) => (i === index ? { ...t, ...patch } : t)),
    })),
  setTerms: (terms) => set({ terms }),
  setMethod: (method) => set({ method }),
  setCreated: (created) => set({ created }),
  setCoupon: (coupon) => set({ coupon }),
  setCouponCheck: (couponCheck) => set({ couponCheck }),
  finish: () =>
    set((state) => ({
      open: false,
      step: 1,
      terms: false,
      addons: [],
      travellers: resize([], state.pax),
      attempted: noAttempts(),
      created: null,
      attemptKey: '',
      coupon: null,
      couponCheck: idleCheck(),
    })),
}));
