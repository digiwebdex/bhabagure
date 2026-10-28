import type { ContentBundle, SiteSettings, WebsiteFeature } from './types';

/** On unless switched off in Admin → Site settings (docs/website-features.md). With no switches at all, everything shows. */
export function featureOn(settings: Pick<SiteSettings, 'features'>, feature: WebsiteFeature): boolean {
  return settings.features?.[feature] !== false;
}

/**
 * The content as the public sees it: a switched-off feature's content is left out, so its section, its links, its pages
 * and its sitemap entries go just as they do when it has none. The content itself, and its admin screen, are untouched;
 * switched on again, it all comes back.
 */
export function withFeatures(bundle: ContentBundle): ContentBundle {
  const on = (feature: WebsiteFeature) => featureOn(bundle.settings, feature);
  return {
    ...bundle,
    posts: on('blog') ? bundle.posts : [],
    team: on('team') ? bundle.team : [],
    reviews: on('reviews') ? bundle.reviews : [],
    gallery: on('gallery') ? bundle.gallery : [],
    visas: on('visas') ? bundle.visas : [],
    creator: on('creator') ? bundle.creator : { profile: null, videos: [] },
    partners: on('partners') ? bundle.partners : [],
    offers: on('offers') ? bundle.offers : [],
    tourPhotos: on('tourPhotos') ? bundle.tourPhotos : [],
    // A landing page stays; its earlier-trip memories are the gallery's and go with it, its reviews with the reviews
    // (docs/tour-landing-pages.md).
    ...(on('tourPhotos') && on('reviews')
      ? {}
      : {
          tourPages: (bundle.tourPages ?? []).map((page) => ({
            ...page,
            ...(on('tourPhotos') ? {} : { memories: [] }),
            ...(on('reviews') ? {} : { reviews: [] }),
          })),
        }),
  };
}
