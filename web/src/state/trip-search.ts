'use client';

import { create } from 'zustand';

import { clampTravellers } from '@bhabaghure/pricing';

import type { BudgetBand } from '@/lib/filter-packages';

/**
 * The search bar's filters. The traveller count drives per-person pricing on every package card,
 * and carries into the detail modal and the booking form.
 *
 * Every counter change uses the functional updater — reading a value captured at render time
 * drops rapid clicks.
 */
export interface TripSearchState {
  destination: string;
  date: string;
  pax: number;
  budget: BudgetBand;
  setDestination: (destination: string) => void;
  setDate: (date: string) => void;
  increasePax: (max: number) => void;
  decreasePax: () => void;
  setBudget: (budget: BudgetBand) => void;
  reset: () => void;
}

const INITIAL = { destination: 'any', date: '', pax: 2, budget: 'any' as BudgetBand };

export const useTripSearch = create<TripSearchState>()((set) => ({
  ...INITIAL,
  setDestination: (destination) => set({ destination }),
  setDate: (date) => set({ date }),
  increasePax: (max) => set((state) => ({ pax: clampTravellers(state.pax + 1, max) })),
  decreasePax: () => set((state) => ({ pax: Math.max(1, state.pax - 1) })),
  setBudget: (budget) => set({ budget }),
  reset: () => set(INITIAL),
}));
