import assert from 'node:assert/strict';
import { test } from 'node:test';

import fixtures from '../fixtures.json' with { type: 'json' };
import {
  formatAudience,
  formatBdt,
  formatBdtCompact,
  formatDate,
  formatDateRange,
  formatNumber,
  formatPercent,
  formatRelativeAge,
  formatWeekdayDate,
  localizeDigits,
  type Locale,
  type MoneyFormatOptions,
} from './index.ts';

type Case = { value: number | string; locale: Locale; options?: MoneyFormatOptions; expected: string };

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

test('formatBdtCompact matches the shared fixtures', () => {
  for (const c of fixtures.formatBdtCompact as Case[]) {
    assert.equal(formatBdtCompact(c.value, c.locale, c.options), c.expected, JSON.stringify(c));
  }
});

test('weekday dates, date ranges and ages match the shared fixtures', () => {
  for (const c of fixtures.formatWeekdayDate as { date: string; locale: Locale; expected: string }[]) {
    assert.equal(formatWeekdayDate(c.date, c.locale), c.expected, JSON.stringify(c));
  }
  for (const c of fixtures.formatDateRange as { start: string; end: string; locale: Locale; expected: string }[]) {
    assert.equal(formatDateRange(c.start, c.end, c.locale), c.expected, JSON.stringify(c));
  }
  for (const c of fixtures.formatRelativeAge as { minutes: number; locale: Locale; expected: string }[]) {
    assert.equal(formatRelativeAge(c.minutes, c.locale), c.expected, JSON.stringify(c));
  }
  assert.throws(() => formatWeekdayDate('22/09/2026', 'en'));
});

test('"৳" is the default in both languages; "BDT" only when asked for', () => {
  assert.equal(formatBdt(1, 'en'), '৳ 1');
  assert.equal(formatBdt(1, 'bn'), '৳ ১');
  assert.equal(formatBdt(1, 'en', { currency: 'code' }), 'BDT 1');
});

test('formatBdtCompact agrees with the full amount at every lakh and crore boundary it rounds across', () => {
  for (let taka = 99_900; taka <= 100_100; taka += 50) {
    const compact = formatBdtCompact(taka, 'en');
    assert.ok(taka < 100_000 ? compact === formatBdt(taka, 'en') : compact === '৳ 1.0L', `${taka} → ${compact}`);
  }
  for (let taka = 9_990_000; taka <= 10_010_000; taka += 1_000) {
    const compact = formatBdtCompact(taka, 'en');
    assert.equal(compact, taka < 9_995_000 ? '৳ 99.9L' : '৳ 1.0Cr', String(taka));
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

test('formatAudience reads like the platforms and never overstates', () => {
  const cases: [number, string, string][] = [
    [1_107_339, '1.1M', '১১ লাখ'],
    [712_000, '712K', '৭.১ লাখ'],
    [712_999, '712K', '৭.১ লাখ'],
    [1_199_999, '1.1M', '১১ লাখ'],
    [1_000_000, '1M', '১০ লাখ'],
    [295, '295', '২৯৫'],
    [0, '0', '০'],
    [999, '999', '৯৯৯'],
    [1_000, '1K', '১ হাজার'],
    [1_550, '1.5K', '১.৫ হাজার'],
    [12_345, '12K', '১২ হাজার'],
    [99_999, '99K', '৯৯ হাজার'],
    [100_000, '100K', '১ লাখ'],
    [25_000_000, '25M', '২.৫ কোটি'],
    [123_456_789_000, '123B', '১২,৩৪৫ কোটি'],
  ];
  for (const [value, en, bn] of cases) {
    assert.equal(formatAudience(value, 'en'), en, `${value} en`);
    assert.equal(formatAudience(value, 'bn'), bn, `${value} bn`);
  }
  // A count can't be negative or fractional; a stray one reads as what it floors to.
  assert.equal(formatAudience(-5, 'en'), '0');
  assert.equal(formatAudience('1550.9', 'en'), '1.5K');
  assert.throws(() => formatAudience('lots', 'en'), TypeError);
});

test('rejects anything that is not a finite number', () => {
  for (const bad of fixtures.invalid) {
    assert.throws(() => formatBdt(bad, 'en'), TypeError, JSON.stringify(bad));
  }
  assert.throws(() => formatNumber(Number.NaN, 'bn'), TypeError);
  assert.throws(() => formatNumber(1, 'en', { decimals: -1 }), RangeError);
});
