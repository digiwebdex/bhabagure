import assert from 'node:assert/strict';
import { test } from 'node:test';

import fixtures from '../fixtures.json' with { type: 'json' };
import { formatBdt, formatDate, formatNumber, formatPercent, localizeDigits, type Locale, type NumberFormatOptions } from './index.ts';

type Case = { value: number | string; locale: Locale; options?: NumberFormatOptions; expected: string };

test('localizeDigits converts in both directions', () => {
  for (const c of fixtures.localizeDigits) {
    assert.equal(localizeDigits(c.text, c.locale as Locale), c.expected, c.text);
  }
});

test('formatNumber matches the shared fixtures', () => {
  for (const c of fixtures.formatNumber as Case[]) {
    assert.equal(formatNumber(c.value, c.locale, c.options), c.expected, JSON.stringify(c));
  }
});

test('formatPercent matches the shared fixtures', () => {
  for (const c of fixtures.formatPercent as { value: number; locale: Locale; expected: string }[]) {
    assert.equal(formatPercent(c.value, c.locale), c.expected, JSON.stringify(c));
  }
});

test('formatBdt matches the shared fixtures', () => {
  for (const c of fixtures.formatBdt as Case[]) {
    assert.equal(formatBdt(c.value, c.locale, c.options), c.expected, JSON.stringify(c));
  }
});

test('hand-rolled grouping agrees with Intl en-IN', () => {
  const inr = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });
  for (const v of [0, 5, 99, 999, 1000, 99_999, 100_000, 1_234_567, 98_765_432, 123_456_789_012]) {
    assert.equal(formatNumber(v, 'en'), inr.format(v), String(v));
  }
});

test('formatDate matches the shared fixtures and rejects malformed dates', () => {
  for (const c of fixtures.formatDate) {
    assert.equal(formatDate(c.date, c.locale as Locale), c.expected, c.date);
  }
  for (const bad of fixtures.invalidDates) {
    assert.throws(() => formatDate(bad, 'en'), TypeError, bad);
  }
});

test('rejects anything that is not a finite number', () => {
  for (const bad of fixtures.invalid) {
    assert.throws(() => formatBdt(bad, 'en'), TypeError, JSON.stringify(bad));
  }
  assert.throws(() => formatNumber(Number.NaN, 'bn'), TypeError);
  assert.throws(() => formatNumber(1, 'en', { decimals: -1 }), RangeError);
});
