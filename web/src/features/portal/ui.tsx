'use client';

import { useTranslations } from 'next-intl';
import type { ReactNode } from 'react';

import type { BookingStatus, DocumentStatus, QuotationStatus, TicketStatus } from './api';

/** A white portal card (Bhabaghure Customer Portal.dc.html: 18px radius, #E1E7F2 border). */
export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`flex flex-col gap-3.5 rounded-18 border border-portal-line bg-app-surface p-fluid-16-22 ${className}`}>{children}</section>;
}

/** "কাগজপত্র Documents": the heading in the active language with the other language beside it, as the design pairs them. */
export function Heading({ id, title, aside, as: Tag = 'h2' }: { id?: string; title: string; aside?: ReactNode; as?: 'h1' | 'h2' }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2.5">
      <Tag id={id} className="m-0 text-18 font-semibold">
        {title}
      </Tag>
      {aside}
    </div>
  );
}

const tones = {
  blue: 'bg-blue-tint text-blue',
  green: 'bg-green-tint text-green',
  orange: 'bg-orange-tint text-amber',
  grey: 'bg-slate-tint text-app-muted',
  red: 'bg-red-tint text-red',
} as const;

export function Pill({ tone, children }: { tone: keyof typeof tones; children: ReactNode }) {
  return <span className={`rounded-pill px-2.5 py-0.75 text-11 font-bold whitespace-nowrap ${tones[tone]}`}>{children}</span>;
}

export function BookingStatusPill({ status }: { status: BookingStatus }) {
  const t = useTranslations('portal.bookingStatus');
  const tone = { inquiry: 'orange', confirmed: 'blue', completed: 'green', cancelled: 'grey' } as const;
  return <Pill tone={tone[status]}>{t(status)}</Pill>;
}

export function QuotationStatusPill({ status }: { status: QuotationStatus }) {
  const t = useTranslations('portal.quotationStatus');
  const tone = { sent: 'blue', expired: 'grey', accepted: 'green', declined: 'grey', converted: 'green' } as const;
  return <Pill tone={tone[status]}>{t(status)}</Pill>;
}

export function TicketStatusPill({ status }: { status: TicketStatus }) {
  const t = useTranslations('portal.ticketStatus');
  const tone = { open: 'orange', answered: 'green', closed: 'grey' } as const;
  return <Pill tone={tone[status]}>{t(status)}</Pill>;
}

export function documentTone(status: DocumentStatus): keyof typeof tones {
  return ({ missing: 'orange', rejected: 'red', uploaded: 'blue', pending: 'grey', verified: 'green', issued: 'green', not_required: 'grey' } as const)[status];
}

/** Loading, missing and failed states, the same everywhere. */
export function LoadState({ state, onRetry }: { state: 'loading' | 'not_found' | 'error'; onRetry?: () => void }) {
  const t = useTranslations('portal');
  return (
    <p role="status" className="flex flex-wrap items-center gap-3 rounded-18 border border-portal-line bg-app-surface p-4.5 text-14 text-app-muted">
      {state === 'loading' ? t('loading') : state === 'not_found' ? t('notFound') : t('loadFailed')}
      {state === 'error' && onRetry ? (
        <button type="button" onClick={onRetry} className="cursor-pointer font-semibold text-blue hover:text-orange">
          {t('retry')}
        </button>
      ) : null}
    </p>
  );
}

/** "12 – 16 Oct 2026", or the one date, or a dash. */
export function dateRange(date: (iso: string) => string, start: string | null, end: string | null): string {
  if (!start) return '—';
  return end && end !== start ? `${date(start)} – ${date(end)}` : date(start);
}
