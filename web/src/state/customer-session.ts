'use client';

import { create } from 'zustand';

export type SessionCustomer = { id: number; name: string; phone: string; email: string | null; locale: 'bn' | 'en' };

/** unknown: not checked yet · signedIn · signedOut. */
export type SessionStatus = 'unknown' | 'signedIn' | 'signedOut';

/**
 * The signed-in customer (docs/phase-6-customer-portal.md §5). The access token lives here, in memory only; the refresh
 * token is an httpOnly cookie on the API host that the page never sees. Session calls are in lib/customer-api.ts.
 */
export interface CustomerSessionState {
  status: SessionStatus;
  customer: SessionCustomer | null;
  accessToken: string | null;
  establish: (accessToken: string, customer: SessionCustomer) => void;
  updateCustomer: (customer: SessionCustomer) => void;
  clear: () => void;
}

export const useCustomerSession = create<CustomerSessionState>()((set) => ({
  status: 'unknown',
  customer: null,
  accessToken: null,
  establish: (accessToken, customer) => set({ status: 'signedIn', accessToken, customer }),
  updateCustomer: (customer) => set({ customer }),
  clear: () => set({ status: 'signedOut', accessToken: null, customer: null }),
}));
