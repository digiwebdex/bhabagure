import assert from 'node:assert/strict';
import { test } from 'node:test';

import { addMonths, isEmail, isPassportNumber, normalizeBdMobile, parseDayMonthYear } from './validators.ts';

test('Bangladeshi mobile numbers normalise to 8801XXXXXXXXX', () => {
  assert.equal(normalizeBdMobile('01743939300'), '8801743939300');
  assert.equal(normalizeBdMobile('+880 1743-939300'), '8801743939300');
  assert.equal(normalizeBdMobile('8801613939200'), '8801613939200');
  // Typed on a Bangla keyboard.
  assert.equal(normalizeBdMobile('০১৭৪৩৯৩৯৩০০'), '8801743939300');
  assert.equal(normalizeBdMobile('01243939300'), null); // no 012 operator prefix
  assert.equal(normalizeBdMobile('0174393930'), null); // one digit short
});

test('email', () => {
  assert.ok(isEmail('bhabaghureholidays@gmail.com'));
  assert.ok(!isEmail('bhabaghure@'));
  assert.ok(!isEmail('a b@c.com'));
});

test('passport numbers: MRP and e-passport formats', () => {
  assert.ok(isPassportNumber('BW0912345'));
  assert.ok(isPassportNumber('a12345678'));
  assert.ok(!isPassportNumber('BW091234'));
  assert.ok(!isPassportNumber('123456789'));
});

test('DD/MM/YYYY parsing rejects impossible dates', () => {
  assert.equal(parseDayMonthYear('14/03/1991'), '1991-03-14');
  assert.equal(parseDayMonthYear('১৪/০৩/১৯৯১'), '1991-03-14');
  assert.equal(parseDayMonthYear('31/02/2026'), null);
  assert.equal(parseDayMonthYear('2026-02-01'), null);
});

test('addMonths clamps to the end of shorter months', () => {
  assert.equal(addMonths('2026-09-17', 6), '2027-03-17');
  assert.equal(addMonths('2026-08-31', 6), '2027-02-28');
  assert.equal(addMonths('2027-08-31', 6), '2028-02-29');
});
