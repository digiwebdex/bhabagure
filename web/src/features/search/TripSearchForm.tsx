'use client';

import { useTranslations } from 'next-intl';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { controlClass, Field } from '@/components/ui/Field';
import { Stepper } from '@/components/ui/Stepper';
import { BUDGET_LIMITS, type BudgetBand } from '@/lib/filter-packages';
import { useFormatters } from '@/lib/use-formatters';
import { todayIso } from '@/lib/validators';
import { useTripSearch } from '@/state/trip-search';

import { searchCardClass } from './styles';
import { usePackageResults } from './usePackageResults';

export function TripSearchForm() {
  const t = useTranslations('search');
  const { destinations, pricing } = useSiteContent();
  const { bdt, number } = useFormatters();
  const search = useTripSearch();
  const { count } = usePackageResults();

  const budgets: { value: BudgetBand; label: string }[] = [
    { value: 'any', label: t('budgetAny') },
    { value: 'low', label: t('budgetLow', { max: bdt(BUDGET_LIMITS.lowMax) }) },
    { value: 'mid', label: t('budgetMid', { min: bdt(BUDGET_LIMITS.lowMax), max: bdt(BUDGET_LIMITS.midMax) }) },
    { value: 'high', label: t('budgetHigh', { min: bdt(BUDGET_LIMITS.midMax) }) },
  ];

  return (
    <div className={searchCardClass}>
      <div className="grid-auto-fit-168 grid gap-3">
        <Field label={t('destination')}>
          <select value={search.destination} onChange={(e) => search.setDestination(e.target.value)} className={controlClass()}>
            <option value="any">{t('allDestinations')}</option>
            {destinations.map((d) => (
              <option key={d.slug} value={d.slug}>
                {d.label}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('date')}>
          <input
            type="date"
            min={todayIso()}
            value={search.date}
            onChange={(e) => search.setDate(e.target.value)}
            className={controlClass()}
            suppressHydrationWarning
          />
        </Field>
        <Field label={t('travellers')}>
          <Stepper
            value={search.pax}
            display={number(search.pax)}
            max={pricing.maxTravellers}
            onDecrease={search.decreasePax}
            onIncrease={() => search.increasePax(pricing.maxTravellers)}
            decreaseLabel={t('fewer')}
            increaseLabel={t('more')}
          />
        </Field>
        <Field label={t('budget')}>
          <select value={search.budget} onChange={(e) => search.setBudget(e.target.value as BudgetBand)} className={controlClass()}>
            {budgets.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <span id="search-results-summary" role="status" className="text-13 text-muted">
          {count > 0
            ? t('counter', { count, countText: number(count), pax: search.pax, paxText: number(search.pax) })
            : t('counterNone')}
        </span>
        <div className="flex flex-wrap gap-2.25">
          <button type="button" onClick={search.reset} className={buttonClass('outline', 'md', 'px-4.5')}>
            {t('reset')}
          </button>
          <a href="#packages" aria-describedby="search-results-summary" className={buttonClass('cta', 'none', 'px-6.5 py-2.75 text-14 shadow-cta')}>
            {t('show')}
          </a>
        </div>
      </div>
    </div>
  );
}
