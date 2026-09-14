'use client';

import { useTranslations } from 'next-intl';

import { useFormatters } from '@/lib/use-formatters';

/** Four-step progress: package · travellers · review · payment. */
export function StepIndicator({ current }: { current: number }) {
  const t = useTranslations('booking');
  const { number } = useFormatters();
  const labels = t.raw('steps') as string[];

  return (
    <ol className="flex flex-wrap gap-2">
      {labels.map((label, i) => {
        const n = i + 1;
        const done = current > n;
        const active = current === n;
        return (
          <li key={label} aria-current={active ? 'step' : undefined} className="flex min-w-35 flex-1 flex-col gap-1.75">
            <span aria-hidden className={`h-1.25 rounded-3 ${done || active ? 'bg-linear-to-r/srgb from-blue to-orange-bright' : 'bg-hairline'}`} />
            <span className="flex items-center gap-2">
              <span
                aria-hidden
                className={`flex size-5.5 shrink-0 items-center justify-center rounded-full font-display text-12 font-extrabold ${
                  done ? 'bg-green text-white' : active ? 'bg-blue text-white' : 'bg-hairline text-ink-soft'
                }`}
              >
                {done ? '✓' : number(n)}
              </span>
              <span className={`min-w-0 text-13 font-semibold ${active ? 'text-ink-deep' : 'text-ink-soft'}`}>{label}</span>
            </span>
          </li>
        );
      })}
    </ol>
  );
}
