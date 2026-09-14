import assert from 'node:assert/strict';
import { test } from 'node:test';

import { portalHostsFrom, resolveRoute } from './host-routing.ts';

const hosts = portalHostsFrom('customer.bhabaghure.com.bd, customer.localhost');

test('apex serves the website in Bangla by default', () => {
  assert.deepEqual(resolveRoute('/', 'bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/bn/site' });
  assert.deepEqual(resolveRoute('/packages/nepal-01', 'bhabaghure.com.bd', hosts), {
    kind: 'rewrite',
    pathname: '/bn/site/packages/nepal-01',
  });
});

test('/en serves English', () => {
  assert.deepEqual(resolveRoute('/en', 'bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/en/site' });
  assert.deepEqual(resolveRoute('/en/', 'localhost:3000', hosts), { kind: 'rewrite', pathname: '/en/site' });
  assert.deepEqual(resolveRoute('/en/blog', 'localhost:3000', hosts), { kind: 'rewrite', pathname: '/en/site/blog' });
});

test('a path that merely starts with "en" is not English', () => {
  assert.deepEqual(resolveRoute('/enquiry', 'bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/bn/site/enquiry' });
});

test('the customer host serves the portal, port ignored', () => {
  assert.deepEqual(resolveRoute('/', 'customer.bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/bn/portal' });
  assert.deepEqual(resolveRoute('/en/payments', 'customer.localhost:3000', hosts), {
    kind: 'rewrite',
    pathname: '/en/portal/payments',
  });
  assert.deepEqual(resolveRoute('/', 'CUSTOMER.Bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/bn/portal' });
});

test('/bn prefixes redirect to the canonical unprefixed URL', () => {
  assert.deepEqual(resolveRoute('/bn', 'bhabaghure.com.bd', hosts), { kind: 'redirect', pathname: '/' });
  assert.deepEqual(resolveRoute('/bn/packages', 'bhabaghure.com.bd', hosts), { kind: 'redirect', pathname: '/packages' });
});

test('internal segments are not reachable from outside', () => {
  // /portal on the apex lands on a non-existent /bn/site/portal route → 404, never the portal.
  assert.deepEqual(resolveRoute('/portal', 'bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/bn/site/portal' });
  assert.deepEqual(resolveRoute('/en/site', 'bhabaghure.com.bd', hosts), { kind: 'rewrite', pathname: '/en/site/site' });
});

test('an empty PORTAL_HOSTS falls back to the production and local hosts', () => {
  assert.deepEqual(portalHostsFrom(undefined), ['customer.bhabaghure.com.bd', 'customer.localhost']);
  assert.deepEqual(portalHostsFrom(' , '), ['customer.bhabaghure.com.bd', 'customer.localhost']);
});
