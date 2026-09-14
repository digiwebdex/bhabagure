'use client';

import { create } from 'zustand';

import { clampTravellers, type RoomType } from '@bhabaghure/pricing';

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
  addons: string[];
  travellers: TravellerDraft[];
  terms: boolean;
  method: PaymentMethod;
  /** Validation messages show once the traveller tries to move on. */
  attempted: Record<BookingStep, boolean>;

  start: (options: { packageSlug: string; date?: string; pax?: number; maxPax: number }) => void;
  close: () => void;
  goBack: () => void;
  goNext: () => void;
  markAttempted: (step: BookingStep) => void;
  setPackage: (slug: string) => void;
  setDate: (date: string) => void;
  setPax: (pax: number, max: number) => void;
  setRoom: (room: RoomType) => void;
  toggleAddon: (code: string) => void;
  updateTraveller: (index: number, patch: Partial<TravellerDraft>) => void;
  setTerms: (terms: boolean) => void;
  setMethod: (method: PaymentMethod) => void;
}

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
  addons: [],
  travellers: resize([], 2),
  terms: false,
  method: 'bkash',
  attempted: noAttempts(),

  start: ({ packageSlug, date, pax, maxPax }) =>
    set((state) => {
      const nextPax = clampTravellers(pax ?? state.pax, maxPax);
      return {
        open: true,
        step: 1,
        packageSlug,
        date: date ?? state.date,
        pax: nextPax,
        travellers: resize(state.travellers, nextPax),
        attempted: noAttempts(),
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
  setPackage: (packageSlug) => set({ packageSlug }),
  setDate: (date) => set({ date }),
  setPax: (pax, max) =>
    set((state) => {
      const nextPax = clampTravellers(pax, max);
      return { pax: nextPax, travellers: resize(state.travellers, nextPax) };
    }),
  setRoom: (room) => set({ room }),
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
}));
