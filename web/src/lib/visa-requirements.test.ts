import assert from 'node:assert/strict';
import { test } from 'node:test';

import { requirementGroups } from './visa-requirements.ts';

test('a line ending in a colon heads the requirements under it', () => {
  assert.deepEqual(
    requirementGroups(['Passport, 6 months validity', 'Recent photo', 'For business person:', 'Trade license', 'Visiting card', 'For student :', 'ব্যবসায়ীদের জন্য：', 'NOC from school']),
    [
      { heading: null, items: ['Passport, 6 months validity', 'Recent photo'] },
      { heading: 'For business person', items: ['Trade license', 'Visiting card'] },
      // A heading with nothing under it is dropped; a full-width colon counts, as Bangla keyboards type it.
      { heading: 'ব্যবসায়ীদের জন্য', items: ['NOC from school'] },
    ],
  );
});

test('a colon inside a line is part of the requirement, and a list of headings alone is empty', () => {
  assert.deepEqual(requirementGroups(['Note: bring the originals']), [{ heading: null, items: ['Note: bring the originals'] }]);
  assert.deepEqual(requirementGroups(['Only a heading:']), []);
  assert.deepEqual(requirementGroups([]), []);
});

test('a list with no headings is one group, as every visa entered before groups existed', () => {
  assert.deepEqual(requirementGroups(['Passport', 'Two photos']), [{ heading: null, items: ['Passport', 'Two photos'] }]);
});
