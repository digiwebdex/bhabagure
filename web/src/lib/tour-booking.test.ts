import assert from 'node:assert/strict';
import { test } from 'node:test';

import { bookingDateFromLink, dhakaToday, printDate } from './tour-booking.ts';

const departures = [
  { departsOn: '2026-11-19', seatsLeft: 6 },
  { departsOn: '2026-12-25', seatsLeft: 0 },
];

test('a Book link opens the form on its date only while that date has seats', () => {
  assert.equal(bookingDateFromLink(departures, '2026-11-19', null), '2026-11-19');
  // Sold out, or not this tour's date: the page's own next departure.
  assert.equal(bookingDateFromLink(departures, '2026-12-25', '2026-11-19'), '2026-11-19');
  assert.equal(bookingDateFromLink(departures, '2027-01-01', '2026-11-19'), '2026-11-19');
  // Nothing usable: no date, and the form asks.
  assert.equal(bookingDateFromLink(departures, 'nonsense', null), null);
  assert.equal(bookingDateFromLink([], '2026-11-19', '2026-11-19'), null);
});

test("the brochure's date is the one asked for, else today in Dhaka", () => {
  assert.equal(printDate('2026-10-28'), '2026-10-28');
  // 20:30 UTC on the 27th is already the 28th in Dhaka (UTC+6).
  const lateEvening = new Date('2026-10-27T20:30:00Z');
  assert.equal(dhakaToday(lateEvening), '2026-10-28');
  assert.equal(printDate(undefined, lateEvening), '2026-10-28');
  assert.equal(printDate('28/10/2026', lateEvening), '2026-10-28');
  assert.equal(printDate(['2026-10-28'], lateEvening), '2026-10-28');
});
