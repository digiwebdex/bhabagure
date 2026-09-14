import assert from 'node:assert/strict';
import { test } from 'node:test';

import { DEFAULT_SLABS } from '@bhabaghure/pricing';

import { filterPackages, inBudget } from './filter-packages.ts';

const packages = [
  { slug: 'mustang', destinationSlug: 'nepal', listPrice: 75000 },
  { slug: 'nature', destinationSlug: 'nepal', listPrice: 18000 },
  { slug: 'pokhara', destinationSlug: 'nepal', listPrice: 13800 },
  { slug: 'nagarkot', destinationSlug: 'nepal', listPrice: 13000 },
  { slug: 'thai-escape', destinationSlug: 'thailand', listPrice: 27000 },
  { slug: 'edge', destinationSlug: 'thailand', listPrice: 16000 },
];

const run = (destination: string, budget: 'any' | 'low' | 'mid' | 'high', pax: number) =>
  filterPackages(packages, { destination, budget, pax }, DEFAULT_SLABS);

test('count always equals the number of cards', () => {
  for (const destination of ['any', 'nepal', 'thailand', 'maldives']) {
    for (const budget of ['any', 'low', 'mid', 'high'] as const) {
      for (const pax of [1, 2, 3, 4, 6, 10, 20]) {
        const { cards, count } = run(destination, budget, pax);
        assert.equal(count, cards.length, `${destination}/${budget}/${pax}`);
      }
    }
  }
});

test('destination filter', () => {
  assert.deepEqual(run('thailand', 'any', 2).cards.map((c) => c.pkg.slug), ['thai-escape', 'edge']);
  assert.equal(run('maldives', 'any', 2).count, 0);
});

test('budget bands test the per-person price shown on the card, not list price', () => {
  // ৳16,000 list: mid band for 2 travellers, but 10 travellers pay ৳14,080 each → low band.
  assert.ok(run('any', 'mid', 2).cards.some((c) => c.pkg.slug === 'edge'));
  assert.ok(run('any', 'low', 10).cards.some((c) => c.pkg.slug === 'edge'));
  assert.ok(!run('any', 'mid', 10).cards.some((c) => c.pkg.slug === 'edge'));
  const edge = run('any', 'low', 10).cards.find((c) => c.pkg.slug === 'edge')!;
  assert.equal(edge.perPerson, 14080);
});

test('bands do not overlap at their edges', () => {
  assert.deepEqual([inBudget(15000, 'low'), inBudget(15000, 'mid')], [true, false]);
  assert.deepEqual([inBudget(30000, 'mid'), inBudget(30000, 'high')], [true, false]);
  assert.deepEqual([inBudget(30001, 'mid'), inBudget(30001, 'high')], [false, true]);
});
