'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useId, useState } from 'react';

import type { EmiSettings } from '@/lib/content/types';
import { emiPlans } from '@/lib/emi';
import { useFormatters } from '@/lib/use-formatters';

/**
 * "See EMI options" under the booking form's first step (docs/emi-preview.md): what each tenure would cost on this
 * booking's amount, per bank group, from the rates in Admin → Site settings → EMI rates. Information only — opening it
 * changes nothing in the booking, and no charge is added to what the customer pays.
 */
export function EmiPreview({ emi, amount, pax }: { emi: EmiSettings; amount: number; pax: number }) {
  const t = useTranslations('booking.emi');
  const f = useFormatters();
  const locale = useLocale() as 'bn' | 'en';
  const id = useId();
  const [open, setOpen] = useState(false);
  const [groupKey, setGroupKey] = useState(emi.groups[0]?.key);
  const group = emi.groups.find((g) => g.key === groupKey) ?? emi.groups[0];
  const eligible = amount >= emi.minAmount;
  const plans = group && eligible ? emiPlans(amount, group.tenures) : [];
  const lowest = eligible ? Math.min(...emi.groups.flatMap((g) => emiPlans(amount, g.tenures).map((p) => p.monthly))) : null;

  if (!group) return null;

  return (
    // shrink-0: the modal's body is a scrolling flex column, where an overflow-hidden child would be squeezed under the
    // add-ons when the step is taller than the screen.
    <section className="shrink-0 overflow-hidden rounded-14 border border-hairline bg-white" data-testid="emi-preview">
      <button
        type="button"
        id={`${id}-trigger`}
        aria-expanded={open}
        aria-controls={`${id}-panel`}
        onClick={() => setOpen((current) => !current)}
        className="flex w-full cursor-pointer items-center justify-between gap-3.5 px-4 py-3.5 text-left"
      >
        <span className="flex min-w-0 flex-col gap-0.5">
          <span className="text-15 font-semibold text-ink-deep">{open ? t('hide') : t('show')}</span>
          {lowest !== null && Number.isFinite(lowest) ? <span className="text-12 text-muted">{t('from', { amount: f.bdt(lowest) })}</span> : null}
        </span>
        <span aria-hidden className={`flex size-6.5 shrink-0 items-center justify-center rounded-8 text-16 transition-colors duration-200 ${open ? 'bg-blue text-white' : 'bg-paper-alt text-ink-deep'}`}>
          <span className={`block transition-transform duration-200 ${open ? 'rotate-45' : ''}`}>+</span>
        </span>
      </button>

      <div id={`${id}-panel`} role="region" aria-labelledby={`${id}-trigger`} hidden={!open} className="flex flex-col gap-3 border-t border-hairline px-4 pt-3.5 pb-4">
        <p className="flex flex-col gap-0.5 rounded-10 bg-paper-alt px-3 py-2.5" data-testid="emi-amount">
          <span className="text-13 text-muted">{t('amountUsed')}</span>
          <strong className="font-display text-18 font-extrabold tracking-heading text-ink">{f.bdt(amount)}</strong>
          <span className="text-12 text-muted">{t('amountNote', { pax, paxText: f.number(pax) })}</span>
        </p>

        {!eligible ? (
          <p role="status" className="rounded-10 bg-orange-tint px-3 py-2.5 text-13 font-semibold text-amber" data-testid="emi-below-minimum">
            {t('belowMinimum', { min: f.bdt(emi.minAmount) })}
          </p>
        ) : (
          <>
            {emi.groups.length > 1 ? (
              <div role="tablist" aria-label={t('bank')} className="flex flex-wrap gap-1.75">
                {emi.groups.map((g) => {
                  const active = g.key === group.key;
                  return (
                    <button
                      key={g.key}
                      type="button"
                      role="tab"
                      aria-selected={active}
                      onClick={() => setGroupKey(g.key)}
                      className={`cursor-pointer rounded-12 border-chip px-3.5 py-2 text-13 font-semibold ${active ? 'border-blue bg-blue-tint text-blue-deep' : 'border-hairline bg-white text-ink'}`}
                    >
                      {g.name[locale] || g.name.en}
                    </button>
                  );
                })}
              </div>
            ) : null}
            {group.eligibilityPending ? <p className="text-12 leading-1.55 text-amber">{t('pending')}</p> : null}
            <ul className="m-0 flex list-none flex-col gap-2 p-0" data-testid="emi-plans">
              {plans.map((plan) => (
                <li key={plan.months} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 rounded-12 border border-hairline px-3.5 py-3" data-months={plan.months}>
                  <span className="flex flex-col gap-0.5">
                    <span className="text-15 font-semibold text-ink-deep">{t('months', { months: plan.months, monthsText: f.number(plan.months) })}</span>
                    <span className="text-12 text-muted">{t('charge', { percent: f.percent(plan.percent) })}</span>
                  </span>
                  <span className="flex flex-col items-end gap-0.5 text-right">
                    <span className="font-display text-17 font-extrabold tracking-heading text-blue-deep">{t('perMonth', { amount: f.bdt(plan.monthly) })}</span>
                    <span className="text-12 text-muted">
                      {t('extra', { amount: f.bdt(plan.extra) })} · {t('total', { amount: f.bdt(plan.total) })}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          </>
        )}
        <p className="text-12 leading-1.55 text-muted">{t('estimate')}</p>
      </div>
    </section>
  );
}
