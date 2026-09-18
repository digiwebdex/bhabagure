'use client';

import { useTranslations } from 'next-intl';

import type { PackageView } from '@/lib/content/views';
import { useFormatters } from '@/lib/use-formatters';

/** The client asked for the price for two and for four side by side (2026-09-19). */
const GROUPS = [2, 4] as const;

/**
 * The package's prices at a glance (docs/package-price-options.md): for two and four travellers, the package price and
 * every extra with an amount (a domestic flight, a resort day tour) — per person and in all — then the costs the agency
 * can only estimate, such as the international air ticket. `rate` is the same per-person price the booking charges.
 */
export function PriceTable({ pkg, rate }: { pkg: PackageView; rate: (travellers: number) => number }) {
  const t = useTranslations('detail.priceTable');
  const f = useFormatters();

  const base = pkg.includesAirfare === false ? t('base.withoutAir') : pkg.includesAirfare ? t('base.withAir') : t('base.plain');
  const rows = [
    { label: base, extra: 0 },
    ...pkg.priceOptions.flatMap((o) => (o.extraPerPerson !== null ? [{ label: o.label, extra: o.extraPerPerson }] : [])),
  ];
  const estimates = pkg.priceOptions.filter((o) => o.estimate !== null);
  const groups = GROUPS.map((pax) => ({ pax, label: t('travellers', { pax, paxText: f.number(pax) }) }));

  return (
    <div className="flex flex-col gap-3" data-testid="price-table">
      <h4 className="text-16 font-bold tracking-heading">{t('heading')}</h4>
      {/* One row per price: the label on its own line on a phone, beside the two prices from a tablet up. Each price says
          which group it is for, so the row reads whole without a header. */}
      <ul aria-label={t('caption', { two: f.number(2), four: f.number(4) })} className="m-0 flex list-none flex-col overflow-hidden rounded-16 border border-hairline bg-white p-0">
        {rows.map((row, index) => (
          <li key={row.label} className={`grid gap-x-4 gap-y-2.5 px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center ${index > 0 ? 'border-t border-hairline' : ''}`}>
            <span className={`text-14 leading-1.4 ${index === 0 ? 'font-bold' : 'font-semibold'}`}>{row.label}</span>
            <span className="grid grid-cols-2 gap-3 sm:gap-6">
              {groups.map((g) => {
                const perPerson = rate(g.pax) + row.extra;
                return (
                  <span key={g.pax} className="flex flex-col items-start sm:items-end">
                    <span className="text-12 font-semibold text-muted">{g.label}</span>
                    <span className="font-display text-17 font-extrabold tracking-price-sm whitespace-nowrap text-orange-deep">
                      {t('total', { amount: f.bdt(perPerson * g.pax) })}
                    </span>
                    <span className="text-12 whitespace-nowrap text-muted">{t('perPerson', { amount: f.bdt(perPerson) })}</span>
                  </span>
                );
              })}
            </span>
          </li>
        ))}
      </ul>

      {estimates.length > 0 ? (
        <div className="flex flex-col gap-1.5 rounded-14 border border-orange-line bg-orange-panel px-4 py-3">
          <span className="text-13 font-bold text-amber">{t('separately')}</span>
          <ul className="flex flex-col gap-1">
            {estimates.map((o) => (
              <li key={o.label} className="text-14 leading-1.5">
                <span className="font-semibold">{o.label}:</span> {o.estimate}
              </li>
            ))}
          </ul>
          <span className="text-12 text-muted">{t('estimateNote')}</span>
        </div>
      ) : null}
    </div>
  );
}
