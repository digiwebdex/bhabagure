/**
 * Single-language views of the content. Built on the server per request locale, so client
 * components receive plain strings in one language instead of every translation.
 */
import { listPrice, savingPercent } from '@bhabaghure/pricing';

import type { AppLocale } from '@/i18n/routing';

import type { BlogCategory, ContentBundle, ContentImage, Localized, TourPackage } from './types';

export interface ImageView {
  url: string;
  alt: string;
  credit: string | null;
  creditUrl: string | null;
  isPlaceholder: boolean;
}

export interface PackageView {
  code: string;
  slug: string;
  destinationSlug: string;
  /** English label — the design keeps destination chips and badges in English in both languages. */
  destinationLabel: string;
  destinationName: string;
  title: string;
  summary: string;
  durationDays: number;
  durationNights: number | null;
  regularPrice: number;
  salePrice: number | null;
  listPrice: number;
  savingPercent: number;
  includesAirfare: boolean | null;
  groupMode: 'group' | 'any';
  minPax: number | null;
  departureMode: TourPackage['departureMode'];
  itinerary: { day: number; title: string; body: string }[];
  includes: string[];
  excludes: string[];
  images: ImageView[];
}

export interface DepartureView {
  packageSlug: string;
  packageTitle: string;
  durationDays: number;
  durationNights: number | null;
  includesAirfare: boolean | null;
  dateLabel: string | null;
  departsOn: string | null;
  seatsTotal: number;
  seatsLeft: number;
  isGuaranteed: boolean;
  listPrice: number;
}

export interface PostView {
  slug: string;
  category: { slug: string; name: string; tone: BlogCategory['tone'] };
  publishedAt: string;
  title: string;
  excerpt: string;
  body: string;
  author: string;
  readingMinutes: number;
}

export interface SiteViews {
  locale: AppLocale;
  /** Destinations that have at least one published package (search select, chips). */
  destinations: { slug: string; label: string; name: string; packageCount: number }[];
  /** Every destination the agency lists, including ones without a package today. */
  allDestinations: { slug: string; label: string; name: string }[];
  packages: PackageView[];
  departures: DepartureView[];
  categories: { slug: string; name: string; tone: BlogCategory['tone'] }[];
  posts: PostView[];
  team: { employeeCode: string; name: string; role: string; roleEn: string; photo: ImageView | null }[];
  reviews: { quote: string; reviewerName: string; tripLabel: string; rating: number }[];
  gallery: { kind: 'reel' | 'photo'; url: string; viewsThousands: number | null; caption: string | null; thumbnail: ImageView | null }[];
  pricing: ContentBundle['pricing'];
  addons: { code: string; name: string; price: number; unit: 'per_person' | 'per_booking' }[];
  settings: ContentBundle['settings'] & { brand: string; companyName: string };
  stats: { packages: number; destinations: number };
}

const pick = (value: Localized, locale: AppLocale) => value[locale] || value.en;

const image = (img: Partial<ContentImage> & { url: string; alt: Localized; isPlaceholder: boolean }, locale: AppLocale): ImageView => ({
  url: img.url,
  alt: pick(img.alt, locale),
  credit: img.credit ?? null,
  creditUrl: img.creditUrl ?? null,
  isPlaceholder: img.isPlaceholder,
});

export function buildViews(bundle: ContentBundle, locale: AppLocale): SiteViews {
  const published = bundle.packages.filter((p) => p.status === 'published');
  const destinationBySlug = new Map(bundle.destinations.map((d) => [d.slug, d]));

  const packages: PackageView[] = published.map((p) => {
    const destination = destinationBySlug.get(p.destination);
    return {
      code: p.code,
      slug: p.slug,
      destinationSlug: p.destination,
      destinationLabel: destination?.name.en ?? p.destination,
      destinationName: destination ? pick(destination.name, locale) : p.destination,
      title: pick(p.title, locale),
      summary: pick(p.summary, locale),
      durationDays: p.durationDays,
      durationNights: p.durationNights,
      regularPrice: p.regularPrice,
      salePrice: p.salePrice,
      listPrice: listPrice(p),
      savingPercent: savingPercent(p),
      includesAirfare: p.includesAirfare,
      groupMode: p.groupMode,
      minPax: p.minPax,
      departureMode: p.departureMode,
      itinerary: p.itinerary.map((d) => ({ day: d.day, title: pick(d.title, locale), body: pick(d.body, locale) })),
      includes: p.includes.map((x) => pick(x, locale)),
      excludes: p.excludes.map((x) => pick(x, locale)),
      images: p.images.map((img) => image(img, locale)),
    };
  });

  const packageByCode = new Map(published.map((p) => [p.code, p]));
  const departures: DepartureView[] = bundle.departures.flatMap((d) => {
    const pkg = packageByCode.get(d.packageCode);
    if (!pkg) return [];
    const view = packages.find((p) => p.slug === pkg.slug)!;
    return [{
      packageSlug: pkg.slug,
      packageTitle: view.title,
      durationDays: view.durationDays,
      durationNights: view.durationNights,
      includesAirfare: view.includesAirfare,
      dateLabel: d.dateLabel ? pick(d.dateLabel, locale) : null,
      departsOn: d.departsOn,
      seatsTotal: d.seatsTotal,
      seatsLeft: Math.max(0, d.seatsTotal - d.seatsBooked),
      isGuaranteed: d.isGuaranteed,
      listPrice: view.listPrice,
    }];
  });

  const categories = bundle.categories.map((c) => ({ slug: c.slug, name: pick(c.name, locale), tone: c.tone }));
  const categoryBySlug = new Map(categories.map((c) => [c.slug, c]));
  const posts: PostView[] = bundle.posts
    .filter((p) => p.status === 'published')
    .sort((a, b) => b.publishedAt.localeCompare(a.publishedAt))
    .map((p) => ({
      slug: p.slug,
      category: categoryBySlug.get(p.category) ?? { slug: p.category, name: p.category, tone: 'blue' },
      publishedAt: p.publishedAt,
      title: pick(p.title, locale),
      excerpt: pick(p.excerpt, locale),
      body: pick(p.body, locale),
      author: pick(p.author, locale),
      readingMinutes: p.readingMinutes,
    }));

  const destinations = bundle.destinations
    .map((d) => ({ slug: d.slug, label: d.name.en, name: pick(d.name, locale), packageCount: packages.filter((p) => p.destinationSlug === d.slug).length }))
    .filter((d) => d.packageCount > 0);

  return {
    locale,
    destinations,
    allDestinations: bundle.destinations.map((d) => ({ slug: d.slug, label: d.name.en, name: pick(d.name, locale) })),
    packages,
    departures,
    categories,
    posts,
    team: bundle.team
      .filter((m) => m.isVisible)
      .sort((a, b) => a.sortOrder - b.sortOrder)
      .map((m) => ({ employeeCode: m.employeeCode, name: pick(m.name, locale), role: pick(m.role, locale), roleEn: m.role.en, photo: m.photo ? image(m.photo, locale) : null })),
    reviews: bundle.reviews.map((r) => ({ quote: pick(r.quote, locale), reviewerName: r.reviewerName, tripLabel: pick(r.tripLabel, locale), rating: r.rating })),
    gallery: bundle.gallery.map((g) => ({
      kind: g.kind,
      url: g.url,
      viewsThousands: g.viewsThousands,
      caption: g.caption ? pick(g.caption, locale) : null,
      thumbnail: g.thumbnail ? image(g.thumbnail, locale) : null,
    })),
    pricing: bundle.pricing,
    addons: bundle.pricing.addons.map((a) => ({ code: a.code, name: pick(a.name, locale), price: a.price, unit: a.unit })),
    settings: { ...bundle.settings, brand: pick(bundle.settings.company.brand, locale), companyName: pick(bundle.settings.company.name, locale) },
    stats: {
      packages: packages.length,
      // The agency's destination list (CMS-managed), not only countries with a package today.
      destinations: bundle.destinations.length,
    },
  };
}
