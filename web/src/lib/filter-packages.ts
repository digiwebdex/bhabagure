import { packagePerPerson, type PriceGrid, type Slab } from '@bhabaghure/pricing';

/**
 * The single source for what the package grid shows. The result counter and the cards both read
 * this function's output, so they cannot disagree — the prototype filtered them separately and
 * they drifted (the counter used the search destination, the cards used the region chips, and the
 * budget test used list price while the cards showed slab price).
 */

export type BudgetBand = 'any' | 'low' | 'mid' | 'high';

/** Per-person price bands, tested against the price the card actually shows. Non-overlapping. */
export const BUDGET_LIMITS = { lowMax: 15000, midMax: 30000 } as const;

export interface PackageLike {
  slug: string;
  destinationSlug: string;
  listPrice: number;
  /** A grid package's card shows basic/3-star for the search bar's travellers (decided 2026-09-16). */
  priceGrid?: PriceGrid | null;
}

export interface PackageFilter {
  destination: string; // 'any' or a destination slug
  budget: BudgetBand;
  pax: number;
}

export interface PackageCard<P extends PackageLike> {
  pkg: P;
  perPerson: number;
}

export function inBudget(perPerson: number, band: BudgetBand): boolean {
  switch (band) {
    case 'any':
      return true;
    case 'low':
      return perPerson <= BUDGET_LIMITS.lowMax;
    case 'mid':
      return perPerson > BUDGET_LIMITS.lowMax && perPerson <= BUDGET_LIMITS.midMax;
    case 'high':
      return perPerson > BUDGET_LIMITS.midMax;
  }
}

export function filterPackages<P extends PackageLike>(
  packages: readonly P[],
  filter: PackageFilter,
  slabs: readonly Slab[],
): { cards: PackageCard<P>[]; count: number } {
  const cards = packages
    .filter((pkg) => filter.destination === 'any' || pkg.destinationSlug === filter.destination)
    .map((pkg) => ({ pkg, perPerson: packagePerPerson(pkg, filter.pax, slabs) }))
    .filter((card) => inBudget(card.perPerson, filter.budget));
  return { cards, count: cards.length };
}
