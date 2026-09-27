import { GRID_TIERS } from '@bhabaghure/pricing';

import type { PackageView } from '@/lib/content/views';

/** The group-size discount steps of a package without a size price table: 1 · 2 · 4 · 6 · 10+. */
const SLAB_SIZES = [1, 2, 4, 6, 10] as const;

/**
 * The group sizes a customized trip is offered at, each with its own price per person (docs/customized-trip-group-sizes.md):
 * the sizes of its price table (1 · 2 · 4 · 6 · 8 · 10 · 12+), else the site-wide discount steps. The last is "or more".
 */
export function groupSizes(pkg: Pick<PackageView, 'hotelCategories'>): readonly number[] {
  return pkg.hotelCategories.length > 0 ? GRID_TIERS : SLAB_SIZES;
}

/**
 * The size chip whose price `pax` travellers pay, to highlight: with a price table the largest size at or below it (5 pay
 * the 4-person price); without one, 1 and 2 only for exactly that many, since 3 is a discount step of its own.
 */
export function activeSize(pkg: Pick<PackageView, 'hotelCategories'>, sizes: readonly number[], pax: number): number | undefined {
  const table = pkg.hotelCategories.length > 0;
  return [...sizes].reverse().find((min) => pax >= min && (table || ((min !== 2 || pax === 2) && (min !== 1 || pax === 1))));
}
