import assert from 'node:assert/strict';
import { test } from 'node:test';

import { emiPlans } from './emi.ts';

// docs/emi-preview.md: the charge is the tenure's percent of the booking amount (an estimate until SSLCommerz confirms),
// added once; no other card fee.
test('each tenure adds its percent once and splits the total into equal instalments', () => {
  const [three, twelve, thirtySix] = emiPlans(150000, [
    { months: 3, percent: 5.13 },
    { months: 12, percent: 11.33 },
    { months: 36, percent: 26.26 },
  ]);
  assert.deepEqual(three, { months: 3, percent: 5.13, extra: 7695, total: 157695, monthly: 52565 });
  assert.deepEqual(twelve, { months: 12, percent: 11.33, extra: 16995, total: 166995, monthly: 13917 });
  assert.deepEqual(thirtySix, { months: 36, percent: 26.26, extra: 39390, total: 189390, monthly: 5261 });
});

test('the charge rounds to the taka and the instalment rounds up, so the instalments cover the total', () => {
  const [plan] = emiPlans(10001, [{ months: 9, percent: 8.99 }]);
  // 10,001 × 8.99 % = 899.0899 → 899; 10,900 ÷ 9 = 1,211.1… → 1,212.
  assert.equal(plan.extra, 899);
  assert.equal(plan.total, 10900);
  assert.equal(plan.monthly, 1212);
  assert.ok(plan.monthly * 9 >= plan.total);
});

test('a tenure that is not offered is simply not in the list', () => {
  // Standard Chartered has no 30 months: the API leaves it out rather than sending 0 %.
  const plans = emiPlans(50000, [
    { months: 24, percent: 23.25 },
    { months: 36, percent: 31.07 },
  ]);
  assert.deepEqual(
    plans.map((p) => p.months),
    [24, 36],
  );
});
