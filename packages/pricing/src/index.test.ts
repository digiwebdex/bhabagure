import assert from 'node:assert/strict';
import { test } from 'node:test';

import fixtures from '../fixtures.json' with { type: 'json' };
import {
  clampTravellers,
  DEFAULT_SLABS,
  defaultHotelCategory,
  gridCategories,
  gridRate,
  invoiceTotals,
  listPrice,
  onlinePayment,
  packagePerPerson,
  paymentStatus,
  perPersonRate,
  quoteBooking,
  savingPercent,
  slabFor,
  type HotelCategory,
  type PriceGrid,
  type PricingConfig,
  type QuoteInput,
} from './index.ts';

const config = fixtures.config as PricingConfig;

test('DEFAULT_SLABS are the decided five tiers', () => {
  assert.deepEqual(DEFAULT_SLABS, config.slabs);
});

test('perPersonRate matches the shared fixtures', () => {
  for (const c of fixtures.perPersonRate) {
    assert.equal(perPersonRate(c.list, c.pax, config.slabs), c.expected, JSON.stringify(c));
  }
});

test('tier boundaries: 1–2 · 3 · 4–5 · 6–9 · 10+', () => {
  const pct = (pax: number) => slabFor(pax).discountPercent;
  assert.deepEqual([1, 2, 3, 4, 5, 6, 9, 10, 20].map(pct), [0, 0, 3, 6, 6, 9, 9, 12, 12]);
});

test('a single traveller pays list price; the solo uplift only comes from a single room', () => {
  const base = { listPrice: 75000, pax: 1, addons: [], config };
  assert.equal(quoteBooking({ ...base, room: 'twin' }).total, 76500);
  assert.equal(quoteBooking({ ...base, room: 'single' }).singleSupplement, 9000);
});

test('quoteBooking matches the shared fixtures', () => {
  for (const c of fixtures.quoteBooking) {
    const quote = quoteBooking({ ...(c.input as Omit<QuoteInput, 'config'>), config });
    const { pax: _pax, slab: _slab, ...amounts } = quote;
    assert.deepEqual(amounts, c.expected, JSON.stringify(c.input));
  }
});

test('gridRate and grid quotes match the shared fixtures', () => {
  const grid = fixtures.grid as PriceGrid;
  assert.deepEqual(gridCategories(grid), ['3', '4', '5']);
  for (const c of fixtures.gridRate) {
    assert.deepEqual(gridRate(grid, c.category as HotelCategory, c.pax), c.expected, JSON.stringify(c));
  }
  for (const c of fixtures.quoteBookingGrid) {
    const quote = quoteBooking({ ...(c.input as Omit<QuoteInput, 'config' | 'grid'>), config, grid });
    const { pax: _pax, slab, ...amounts } = quote;
    assert.deepEqual(amounts, c.expected, c.$comment);
    assert.equal(slab.discountPercent, 0, 'a grid never takes the group discount too');
  }
});

test('cards show basic/3-star by default, another category on request, and the slab price without a grid', () => {
  const grid = fixtures.grid as PriceGrid;
  assert.equal(defaultHotelCategory(grid), '3');
  assert.equal(defaultHotelCategory({ 5: grid['5'], 4: grid['4'] }), '4');
  assert.equal(defaultHotelCategory(null), null);
  assert.equal(packagePerPerson({ listPrice: 1, priceGrid: grid }, 3, config.slabs), 75000);
  assert.equal(packagePerPerson({ listPrice: 1, priceGrid: grid }, 3, config.slabs, '4'), 90000);
  assert.equal(packagePerPerson({ listPrice: 1, priceGrid: { 4: grid['4'] } }, 1, config.slabs, '3'), 120000);
  assert.equal(packagePerPerson({ listPrice: 75000, priceGrid: null }, 3, config.slabs), 72750);
});

test('a grid package needs one of its offered categories; an empty grid is no grid', () => {
  const grid = fixtures.grid as PriceGrid;
  const base = { listPrice: 75000, pax: 2, room: 'twin' as const, addons: [], config };
  assert.throws(() => quoteBooking({ ...base, grid }), RangeError);
  assert.throws(() => quoteBooking({ ...base, grid: { 3: grid['3'] }, hotelCategory: '5' }), RangeError);
  // A row without the 1-traveller price isn't offered.
  assert.deepEqual(gridCategories({ 4: { 2: 90000 } }), []);
  assert.equal(quoteBooking({ ...base, grid: {}, hotelCategory: '3' }).total, 153000);
  assert.equal(quoteBooking({ ...base, grid: {} }).hotelCategory, null);
});

test('invoiceTotals matches the shared fixtures: discount first, then VAT', () => {
  for (const c of fixtures.invoiceTotals) {
    assert.deepEqual(invoiceTotals(c.input), c.expected, c.$comment);
  }
});

test('paymentStatus is derived from the amounts', () => {
  for (const c of fixtures.paymentStatus) {
    assert.equal(paymentStatus(c.total, c.paid), c.expected, JSON.stringify(c));
  }
});

test('a quote is exactly its lines run through invoiceTotals', () => {
  const quote = quoteBooking({ listPrice: 27000, pax: 4, room: 'single', addons: [{ code: 'airport-pickup', price: 800, unit: 'per_booking' }], config, discount: 1000 });
  const totals = invoiceTotals({ lines: quote.lines, discount: 1000, chargePercent: config.serviceChargePercent });
  assert.equal(quote.total, totals.total);
  assert.equal(quote.serviceCharge, totals.charge);
});

test('list price and saving follow the WordPress sale labels', () => {
  assert.equal(listPrice({ regularPrice: 80000, salePrice: 75000 }), 75000);
  assert.equal(listPrice({ regularPrice: 18000, salePrice: null }), 18000);
  // WordPress: "6% Off", "10% Off", "13% Off".
  assert.equal(savingPercent({ regularPrice: 80000, salePrice: 75000 }), 6);
  assert.equal(savingPercent({ regularPrice: 30000, salePrice: 27000 }), 10);
  assert.equal(savingPercent({ regularPrice: 26000, salePrice: 22500 }), 13);
});

test('guards', () => {
  assert.throws(() => perPersonRate(75000, 0), RangeError);
  assert.throws(() => perPersonRate(75000, 2.5), RangeError);
  assert.throws(() => quoteBooking({ listPrice: 1, pax: 21, room: 'twin', addons: [], config }), RangeError);
  assert.equal(clampTravellers(0, 20), 1);
  assert.equal(clampTravellers(99, 20), 20);
  assert.equal(clampTravellers(Number.NaN, 20), 1);
});

test('onlinePayment matches the shared fixtures: the charge is its own whole-taka line', () => {
  for (const c of fixtures.onlinePayment) {
    assert.deepEqual(onlinePayment(c.amount, c.chargePercent), c.expected, JSON.stringify(c));
  }
});
