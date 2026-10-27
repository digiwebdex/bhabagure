/** The staff preview's HttpOnly cookie (docs/tour-landing-pages.md §6): set by /api/tour-preview from the link's #hash. */
export const TOUR_PREVIEW_COOKIE = 'bh_tour_preview';

/** Its life: the token's own 30 minutes at most — the API refuses it after that anyway. */
export const TOUR_PREVIEW_MAX_AGE = 30 * 60;
