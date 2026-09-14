'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';

import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';
import { useFormatters } from '@/lib/use-formatters';

import { usePortal, type QuotationSummary, type TripsData, type TripSummary } from './api';
import { BookingStatusPill, Card, dateRange, Heading, LoadState, QuotationStatusPill } from './ui';

type Filter = 'all' | 'upcoming' | 'completed';

/** My trips: the next trip with its readiness, open quotations, and every booking (Bhabaghure Customer Portal.dc.html). */
export function TripsView() {
  const t = useTranslations('portal.trips');
  const f = useFormatters();
  const [trips, reloadTrips] = usePortal<TripsData>('portal/trips');
  const [quotations] = usePortal<QuotationSummary[]>('portal/quotations');
  const [filter, setFilter] = useState<Filter>('all');

  if (trips.state !== 'ready') return <LoadState state={trips.state} onRetry={reloadTrips} />;
  const { next } = trips.data;
  const shown = trips.data.trips.filter((trip) => filter === 'all' || (filter === 'upcoming' ? trip.upcoming : trip.status === 'completed'));
  const offers = quotations.state === 'ready' ? quotations.data.filter((q) => q.status === 'sent' || q.status === 'accepted') : [];

  return (
    <>
      {next ? (
        <section aria-labelledby="next-trip" className="grid-auto-fit-220 grid items-center gap-5 rounded-20 bg-linear-135/srgb from-blue to-blue-abyss p-fluid-18-26 text-white">
          <div className="flex min-w-0 flex-col gap-2">
            <span className="font-display text-11 font-bold tracking-[0.16em] text-orange-glow uppercase">{t('nextTrip')}</span>
            <strong id="next-trip" className="text-fluid-19-25 leading-1.25 font-bold">
              {next.title}
            </strong>
            <span className="text-14 opacity-90">
              {dateRange(f.date, next.travelStart, next.travelEnd)} · {t('travellers', { count: next.pax, countText: f.number(next.pax) })}
            </span>
            <div className="mt-1.5 flex flex-wrap gap-2.25">
              <Link href={`/trips/${next.reference}`} className={buttonClass('cta', 'md')}>
                {t('viewTrip')}
              </Link>
              {next.invoice ? (
                <a href={next.invoice.url} target="_blank" rel="noopener noreferrer" className="rounded-pill border border-white/60 px-4.5 py-2.5 text-14 font-semibold whitespace-nowrap text-white hover:bg-white hover:text-blue">
                  {t('invoice')}
                </a>
              ) : null}
            </div>
          </div>
          <div className="flex flex-col gap-2.5">
            <span className="text-13 opacity-85">{t('readiness')}</span>
            <div
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={next.readiness.total}
              aria-valuenow={next.readiness.done}
              aria-label={t('readiness')}
              className="h-2.25 overflow-hidden rounded-5 bg-white/20"
            >
              <div className="h-full bg-green-soft" style={{ width: `${Math.round((next.readiness.done / Math.max(1, next.readiness.total)) * 100)}%` }} />
            </div>
            {next.readiness.checks.map((check) => (
              <span key={check.key} className="flex items-center gap-2.25 text-13">
                <span aria-hidden className={`flex size-4.25 shrink-0 items-center justify-center rounded-5 text-11 font-extrabold text-ink-deep ${check.done ? 'bg-green-soft' : 'bg-orange-glow'}`}>
                  {check.done ? '✓' : '!'}
                </span>
                {check.done ? t(`checks.${check.key}.done`) : t(`checks.${check.key}.waiting`, { names: check.waitingOn.join(', ') })}
              </span>
            ))}
            {next.daysToGo !== null ? (
              <span className="text-12 opacity-80">{next.daysToGo === 0 ? t('departsToday') : t('daysToGo', { count: next.daysToGo, countText: f.number(next.daysToGo) })}</span>
            ) : null}
          </div>
        </section>
      ) : null}

      {offers.length > 0 ? (
        <Card>
          <Heading title={t('quotations')} />
          <div className="grid-auto-fit-280 grid gap-3">
            {offers.map((quotation) => (
              <Link
                key={quotation.number}
                href={`/quotations/${quotation.number}`}
                className="flex flex-col gap-1.5 rounded-14 border border-portal-line bg-app-surface-2 p-3.5 text-app-text hover:border-blue hover:text-app-text"
              >
                <span className="flex items-start justify-between gap-2.5">
                  <span className="font-display text-12 text-app-muted">{quotation.number}</span>
                  <QuotationStatusPill status={quotation.status} />
                </span>
                <strong className="text-15 leading-1.3 font-semibold">{quotation.title}</strong>
                <span className="text-13 text-app-muted">
                  {f.bdt(quotation.total)} · {t('validUntil', { date: f.date(quotation.validUntil) })}
                </span>
              </Link>
            ))}
          </div>
        </Card>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-2.5">
        <h2 className="m-0 text-19 font-semibold">{t('myBookings')}</h2>
        <div role="group" aria-label={t('filterLabel')} className="flex flex-wrap gap-1.75">
          {(['all', 'upcoming', 'completed'] as const).map((key) => (
            <button
              key={key}
              type="button"
              aria-pressed={filter === key}
              onClick={() => setFilter(key)}
              className={`cursor-pointer rounded-pill border-chip px-3.25 py-1.5 text-12 font-semibold ${
                filter === key ? 'border-blue bg-blue text-white' : 'border-portal-line bg-app-surface text-app-text'
              }`}
            >
              {t(`filters.${key}`)}
            </button>
          ))}
        </div>
      </div>

      {shown.length === 0 ? (
        <p className="rounded-18 border border-portal-line bg-app-surface p-4.5 text-14 text-app-muted">{trips.data.trips.length === 0 ? t('noTrips') : t('noneInFilter')}</p>
      ) : (
        <div className="grid-auto-fit-300 grid gap-3.5">
          {shown.map((trip) => (
            <TripCard key={trip.reference} trip={trip} />
          ))}
        </div>
      )}
    </>
  );
}

function TripCard({ trip }: { trip: TripSummary }) {
  const t = useTranslations('portal.trips');
  const f = useFormatters();

  return (
    <article className="flex flex-col gap-2.75 rounded-18 border border-portal-line bg-app-surface p-4.5">
      <div className="flex items-start justify-between gap-2.5">
        <span className="font-display text-12 text-app-muted">{trip.reference}</span>
        <BookingStatusPill status={trip.status} />
      </div>
      <strong className="text-16 leading-1.3 font-semibold">{trip.title}</strong>
      <span className="text-13 text-app-muted">
        {dateRange(f.date, trip.travelStart, trip.travelEnd)} · {t('travellers', { count: trip.pax, countText: f.number(trip.pax) })}
      </span>
      <div className="mt-auto flex items-end justify-between gap-2.5 border-t border-portal-line pt-2">
        <span className="flex flex-col">
          <span className="text-11 text-app-muted">{trip.due > 0 && trip.status !== 'cancelled' ? t('due') : t('total')}</span>
          <span className={`font-display text-17 font-extrabold ${trip.due > 0 && trip.status !== 'cancelled' ? 'text-amber' : ''}`}>
            {f.bdt(trip.due > 0 && trip.status !== 'cancelled' ? trip.due : trip.total)}
          </span>
        </span>
        <div className="flex flex-wrap gap-1.5">
          {trip.invoice ? (
            <a
              href={trip.invoice.url}
              target="_blank"
              rel="noopener noreferrer"
              className="rounded-8 border border-portal-input bg-app-surface px-3 py-1.75 text-12 font-semibold whitespace-nowrap text-app-text hover:border-orange hover:text-orange"
            >
              {t('invoice')}
            </a>
          ) : null}
          <Link href={`/trips/${trip.reference}`} className="rounded-8 bg-blue-wash px-3 py-1.75 text-12 font-semibold whitespace-nowrap text-blue hover:text-orange">
            {t('details')}
          </Link>
        </div>
      </div>
    </article>
  );
}
