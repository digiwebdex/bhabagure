'use client';

import { create } from 'zustand';

import { clampTravellers, type HotelCategory } from '@bhabaghure/pricing';

/**
 * Open panels and the package detail modal. The modal keeps its own traveller count, seeded from
 * the search bar when it opens, so exploring slabs in the modal doesn't change the grid.
 */
export interface SiteUiState {
  packageSlug: string | null;
  /** How the modal was opened: by a click (we push a /packages/<slug> history entry) or by history. */
  packageOpenedBy: 'click' | 'history' | null;
  detailPax: number;
  /** A grid package's hotel category in the modal; null until picked (basic/3-star shows). */
  detailCategory: HotelCategory | null;
  photoIndex: number;
  menuOpen: boolean;
  authOpen: boolean;
  chatOpen: boolean;

  openPackage: (slug: string, pax: number, openedBy?: 'click' | 'history') => void;
  closePackage: () => void;
  increaseDetailPax: (max: number) => void;
  decreaseDetailPax: () => void;
  setDetailPax: (pax: number, max: number) => void;
  setDetailCategory: (category: HotelCategory) => void;
  setPhotoIndex: (index: number) => void;
  toggleMenu: () => void;
  closeMenu: () => void;
  openAuth: () => void;
  closeAuth: () => void;
  toggleChat: () => void;
  closeChat: () => void;
}

export const useSiteUi = create<SiteUiState>()((set) => ({
  packageSlug: null,
  packageOpenedBy: null,
  detailPax: 2,
  detailCategory: null,
  photoIndex: 0,
  menuOpen: false,
  authOpen: false,
  chatOpen: false,

  openPackage: (slug, pax, openedBy = 'click') =>
    set({ packageSlug: slug, packageOpenedBy: openedBy, detailPax: Math.max(1, pax), detailCategory: null, photoIndex: 0, menuOpen: false }),
  closePackage: () => set({ packageSlug: null, packageOpenedBy: null }),
  increaseDetailPax: (max) => set((state) => ({ detailPax: clampTravellers(state.detailPax + 1, max) })),
  decreaseDetailPax: () => set((state) => ({ detailPax: Math.max(1, state.detailPax - 1) })),
  setDetailPax: (pax, max) => set({ detailPax: clampTravellers(pax, max) }),
  setDetailCategory: (detailCategory) => set({ detailCategory }),
  setPhotoIndex: (photoIndex) => set({ photoIndex }),
  toggleMenu: () => set((state) => ({ menuOpen: !state.menuOpen })),
  closeMenu: () => set({ menuOpen: false }),
  openAuth: () => set({ authOpen: true, menuOpen: false }),
  closeAuth: () => set({ authOpen: false }),
  toggleChat: () => set((state) => ({ chatOpen: !state.chatOpen })),
  closeChat: () => set({ chatOpen: false }),
}));
