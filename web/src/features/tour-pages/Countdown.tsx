'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';

import { useFormatters } from '@/lib/use-formatters';

/** Midnight in Dhaka on the departure date: departures have a date, not a flight time. */
export const departureStart = (departsOn: string) => Date.parse(`${departsOn}T00:00:00+06:00`);

/**
 * The time left to a departure (docs/tour-landing-pages.md), by the visitor's own clock once the page is in the browser —
 * nothing on the server, so a cached page never shows a stale count. Gone once the date has come.
 */
export function Countdown({ departsOn, compact = false, tone = 'light' }: { departsOn: string; compact?: boolean; tone?: 'light' | 'dark' }) {
  const t = useTranslations('tourPage');
  const f = useFormatters();
  const [now, setNow] = useState<number | null>(null);

  useEffect(() => {
    const tick = () => setNow(Date.now());
    const first = window.setTimeout(tick, 0);
    const timer = window.setInterval(tick, 30_000);
    return () => {
      window.clearTimeout(first);
      window.clearInterval(timer);
    };
  }, []);

  if (now === null) return null;
  const left = departureStart(departsOn) - now;
  if (left <= 0) return null;
  const days = Math.floor(left / 86_400_000);
  const hours = Math.floor((left % 86_400_000) / 3_600_000);
  const minutes = Math.floor((left % 3_600_000) / 60_000);

  if (compact) {
    return (
      <span data-testid="tour-days-to-go" className="font-display font-semibold">
        {days > 0 ? t('daysToGo', { n: days, nText: f.number(days) }) : t('leavesToday')}
      </span>
    );
  }

  const box = tone === 'dark' ? 'bg-white/12 text-white ring-1 ring-white/20' : 'bg-white text-ink ring-1 ring-hairline';
  return (
    <div role="timer" aria-label={t('countdownLabel')} className="flex flex-wrap gap-2.5" data-testid="tour-countdown">
      {[
        [days, t('days')],
        [hours, t('hours')],
        [minutes, t('minutes')],
      ].map(([value, label]) => (
        <span key={label as string} className={`flex min-w-18 flex-col items-center rounded-14 px-3 py-2.5 ${box}`}>
          <span className="font-display text-30 leading-1 font-extrabold tabular-nums">{f.number(value as number)}</span>
          <span className="mt-1 text-12 opacity-80">{label}</span>
        </span>
      ))}
    </div>
  );
}
