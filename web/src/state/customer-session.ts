'use client';

import { create } from 'zustand';

/**
 * The signed-in customer, for the header pill. In memory only: the access token will live here
 * once the customer auth API exists (refresh token in an httpOnly cookie on the API host).
 */
export interface CustomerSessionState {
  customer: { name: string } | null;
  signIn: (customer: { name: string }) => void;
  signOut: () => void;
}

export const useCustomerSession = create<CustomerSessionState>()((set) => ({
  customer: null,
  signIn: (customer) => set({ customer }),
  signOut: () => set({ customer: null }),
}));

