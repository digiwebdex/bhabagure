import type { EmiTenure } from './content/types.ts';

export interface EmiPlan {
  months: number;
  percent: number;
  /** The EMI charge on the booking amount, to the nearest taka. */
  extra: number;
  /** The booking amount plus the charge. */
  total: number;
  /** One instalment, rounded up to the taka so the instalments cover the total. */
  monthly: number;
}

/**
 * The EMI preview's figures (docs/emi-preview.md) — an estimate: SSLCommerz's chart gives a percent per tenure with card
 * and EMI charges included, but not how the bank applies it, so the charge is taken as that percent of the booking
 * amount, added once, and split into equal instalments. No other card fee is added. Change it here if SSLCommerz
 * confirms a different calculation.
 */
export function emiPlans(amount: number, tenures: readonly EmiTenure[]): EmiPlan[] {
  return tenures.map(({ months, percent }) => {
    const extra = Math.round((amount * percent) / 100);
    const total = amount + extra;
    return { months, percent, extra, total, monthly: Math.ceil(total / months) };
  });
}
