import 'server-only';

import { cache } from 'react';

import type { ContentBundle } from './types';

/**
 * Where content comes from.
 *
 *   CONTENT_SOURCE=api   (production)  Laravel public API at API_URL, cached per tag and refreshed
 *                                      by POST /api/revalidate when the CMS saves.
 *   CONTENT_SOURCE=seed  (local)       packages/content-seed — the same JSON the Laravel seeders load.
 *   CONTENT_DEMO=1       (local only)  also loads seed demo/ departures, reviews and gallery items
 *                                      so those sections can be checked visually.
 *
 * There is deliberately no default: a server without CONTENT_SOURCE fails loudly instead of
 * quietly serving seed or demo content.
 */
export const loadContent = cache(async (): Promise<ContentBundle> => {
  const source = process.env.CONTENT_SOURCE;
  if (source === 'seed') return loadSeed(process.env.CONTENT_DEMO === '1');
  if (source === 'api') return loadFromApi();
  throw new Error('CONTENT_SOURCE must be "api" or "seed" — see web/.env.example');
});

async function loadSeed(withDemo: boolean): Promise<ContentBundle> {
  const [destinations, packages, posts, team, pricing, settings] = await Promise.all([
    import('@bhabaghure/content-seed/destinations.json'),
    import('@bhabaghure/content-seed/packages.json'),
    import('@bhabaghure/content-seed/posts.json'),
    import('@bhabaghure/content-seed/team.json'),
    import('@bhabaghure/content-seed/pricing.json'),
    import('@bhabaghure/content-seed/settings.json'),
  ]);
  const demo = withDemo
    ? await Promise.all([
        import('@bhabaghure/content-seed/demo/departures.json'),
        import('@bhabaghure/content-seed/demo/reviews.json'),
        import('@bhabaghure/content-seed/demo/gallery.json'),
      ])
    : null;

  return {
    destinations: destinations.default as ContentBundle['destinations'],
    packages: packages.default as ContentBundle['packages'],
    categories: posts.default.categories as ContentBundle['categories'],
    posts: posts.default.posts as ContentBundle['posts'],
    team: team.default as ContentBundle['team'],
    pricing: pricing.default as ContentBundle['pricing'],
    settings: settings.default as ContentBundle['settings'],
    departures: (demo?.[0].default ?? []) as ContentBundle['departures'],
    reviews: (demo?.[1].default ?? []) as ContentBundle['reviews'],
    gallery: (demo?.[2].default ?? []) as ContentBundle['gallery'],
    // Visa services and the travel host exist only in the CMS: nothing is invented for local previews.
    visas: [],
    creator: { profile: null, videos: [] },
    partners: [],
  };
}

/** Endpoint shapes: docs/phase-2-cms-api.md §4. Kept in step with the seed by api/tests/Feature/PublicContentContractTest. */
async function loadFromApi(): Promise<ContentBundle> {
  // On the server the API is reached over loopback (API_INTERNAL_URL) rather than out through the CDN and back.
  const base = process.env.API_INTERNAL_URL || process.env.API_URL;
  if (!base) throw new Error('CONTENT_SOURCE=api needs API_URL');
  const get = async <T,>(path: string, tag: string, { empty }: { empty?: T } = {}): Promise<T> => {
    const res = await fetch(`${base}/api/v1/public/${path}`, { next: { tags: [tag], revalidate: 3600 } });
    // A list newer than the API that answers: deploy.sh builds the website before it migrates and reloads the API, so the
    // build talks to the previous release. It renders empty, and the refresh at the end of the deploy fills it in.
    if (res.status === 404 && empty !== undefined) {
      console.warn(`GET ${path} → 404: rendering it empty until the API serves it`);
      return empty;
    }
    if (!res.ok) throw new Error(`GET ${path} → ${res.status}`);
    return (await res.json()).data as T;
  };
  const [destinations, packages, departures, blog, team, reviews, gallery, visas, creator, partners, pricing, settings] = await Promise.all([
    get<ContentBundle['destinations']>('destinations', 'packages'),
    get<ContentBundle['packages']>('packages', 'packages'),
    get<ContentBundle['departures']>('departures', 'departures'),
    get<{ categories: ContentBundle['categories']; posts: ContentBundle['posts'] }>('posts', 'posts'),
    get<ContentBundle['team']>('team', 'team'),
    get<ContentBundle['reviews']>('reviews', 'reviews'),
    get<ContentBundle['gallery']>('gallery', 'gallery'),
    get<ContentBundle['visas']>('visas', 'visas', { empty: [] }),
    get<ContentBundle['creator']>('creator', 'creator', { empty: { profile: null, videos: [] } }),
    get<ContentBundle['partners']>('partners', 'partners', { empty: [] }),
    get<ContentBundle['pricing']>('pricing', 'settings'),
    get<ContentBundle['settings']>('settings', 'settings'),
  ]);
  return { destinations, packages, departures, categories: blog.categories, posts: blog.posts, team, reviews, gallery, visas, creator, partners, pricing, settings };
}
