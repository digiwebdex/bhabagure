import assert from 'node:assert/strict';
import { test } from 'node:test';

import { featureOn, withFeatures } from './content/features.ts';
import type { ContentBundle, WebsiteFeature } from './content/types.ts';

const LISTS = { blog: 'posts', team: 'team', reviews: 'reviews', gallery: 'gallery', visas: 'visas', partners: 'partners', offers: 'offers', tourPhotos: 'tourPhotos' } as const;

/** One of everything, so a list left out shows. */
function bundle(features?: Partial<Record<WebsiteFeature, boolean>>): ContentBundle {
  return {
    destinations: [{}], packages: [{}], departures: [{}], categories: [{}], posts: [{}], team: [{}], reviews: [{}], gallery: [{}], visas: [{}],
    creator: { profile: {}, videos: [{}] }, partners: [{}], offers: [{}], tourPhotos: [{}], pricing: {},
    settings: { features },
  } as unknown as ContentBundle;
}

test('with no switches (an older API, the seed) every feature shows and nothing is left out', () => {
  const content = bundle();
  assert.deepEqual(withFeatures(content), content);
  for (const feature of [...Object.keys(LISTS), 'creator'] as WebsiteFeature[]) assert.equal(featureOn(content.settings, feature), true);
});

test('each feature switched off leaves out its own content and nothing else', () => {
  for (const [feature, list] of Object.entries(LISTS) as [WebsiteFeature, keyof ContentBundle][]) {
    const shown = withFeatures(bundle({ [feature]: false }));
    assert.deepEqual(shown[list], [], `${feature} off empties ${list}`);
    for (const other of Object.values(LISTS).filter((name) => name !== list)) assert.equal((shown[other] as unknown[]).length, 1, `${feature} off keeps ${other}`);
    assert.equal(shown.packages.length, 1);
    assert.notEqual(shown.creator.profile, null);
  }
  const noHost = withFeatures(bundle({ creator: false }));
  assert.deepEqual(noHost.creator, { profile: null, videos: [] });
  assert.equal(noHost.posts.length, 1);
});

test('a landing page stays with the gallery off, without its earlier-trip memories', () => {
  const page = { slug: 'mustang', packageSlug: 'mustang-trip', memories: [{}], days: [{ day: 1, photos: [{}] }] };
  const on = withFeatures({ ...bundle(), tourPages: [page] } as unknown as ContentBundle);
  assert.equal(on.tourPages[0].memories.length, 1);
  const off = withFeatures({ ...bundle({ tourPhotos: false }), tourPages: [page] } as unknown as ContentBundle);
  assert.equal(off.tourPages.length, 1);
  assert.deepEqual(off.tourPages[0].memories, []);
  // Its own day photos are the page's, not the gallery's.
  assert.equal(off.tourPages[0].days[0].photos.length, 1);
});

test('a landing page keeps its videos with reviews off, without its reviews', () => {
  const page = { slug: 'mustang', packageSlug: 'mustang-trip', memories: [{}], reviews: [{}], videos: [{}], days: [] };
  const on = withFeatures({ ...bundle(), tourPages: [page] } as unknown as ContentBundle);
  assert.equal(on.tourPages[0].reviews.length, 1);
  const off = withFeatures({ ...bundle({ reviews: false }), tourPages: [page] } as unknown as ContentBundle);
  assert.deepEqual(off.tourPages[0].reviews, []);
  assert.equal(off.tourPages[0].videos.length, 1);
  assert.equal(off.tourPages[0].memories.length, 1);
  const both = withFeatures({ ...bundle({ reviews: false, tourPhotos: false }), tourPages: [page] } as unknown as ContentBundle);
  assert.deepEqual([both.tourPages[0].reviews, both.tourPages[0].memories], [[], []]);
});

test('only an explicit false switches a feature off', () => {
  assert.equal(featureOn({ features: { blog: true } }, 'blog'), true);
  assert.equal(featureOn({ features: {} }, 'team'), true);
  assert.equal(featureOn({ features: { team: false } }, 'team'), false);
});
