import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';

/** Navy strip of destinations scrolling right to left. The list repeats once for a seamless loop. */
export async function Marquee({ locale }: { locale: AppLocale }) {
  const t = await getTranslations({ locale });
  const items = t.raw('marquee') as string[];
  const loop = [...items, ...items];

  return (
    <div className="overflow-hidden border-b border-white/8 bg-ink-deep py-3.5 text-white">
      <p className="sr-only">{items.join(', ')}</p>
      <div aria-hidden className="flex w-max animate-marquee">
        {loop.map((label, i) => (
          <span
            key={`${label}-${i}`}
            className={`flex items-center gap-3.5 px-5.5 font-display text-fluid-13-15 font-semibold tracking-menu whitespace-nowrap ${
              i % 3 === 1 ? 'text-orange-soft' : 'text-white'
            }`}
          >
            {label}
            <span className="size-1.25 shrink-0 rounded-full bg-orange" />
          </span>
        ))}
      </div>
    </div>
  );
}
