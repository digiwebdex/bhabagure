'use client';

import { createContext, useContext, type ReactNode } from 'react';

import type { SiteViews } from '@/lib/content/views';

const SiteContentContext = createContext<SiteViews | null>(null);

/** Hands the page's single-language content to client islands (search, modals, assistant). Never changes after render. */
export function SiteContentProvider({ value, children }: { value: SiteViews; children: ReactNode }) {
  return <SiteContentContext.Provider value={value}>{children}</SiteContentContext.Provider>;
}

export function useSiteContent(): SiteViews {
  const value = useContext(SiteContentContext);
  if (!value) throw new Error('useSiteContent must be used inside <SiteContentProvider>');
  return value;
}
