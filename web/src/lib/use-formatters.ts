'use client';

import { useLocale } from 'next-intl';
import { useMemo } from 'react';

import type { AppLocale } from '@/i18n/routing';

import { formattersFor } from './formatters';

/** Client components render numbers only through this. */
export function useFormatters() {
  const locale = useLocale() as AppLocale;
  return useMemo(() => formattersFor(locale), [locale]);
}
